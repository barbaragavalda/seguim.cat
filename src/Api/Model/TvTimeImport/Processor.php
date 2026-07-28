<?php

namespace Api\Model\TvTimeImport;

use Api\Model\Episode;
use Api\Model\Movie;
use Api\Model\MovieWatchlist;
use Api\Model\Series;
use Api\Model\TheTvdb\Client;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Api\Model\WatchedEpisode;
use Api\Model\WatchedMovie;
use Api\Model\Watchlist;

/**
 * Applies a parsed TV Time export (see Parser) to a real account: syncs
 * each show from TheTVDB using the same Series/Episode models the rest of
 * this app already uses (so there's no separate mirroring logic to keep in
 * sync), adds it to the watchlist with its archived/removed flags, marks
 * every matched episode watched/rewatched, and - once every show is done -
 * recreates the account's own custom lists.
 *
 * Resumable and time-boxed rather than all-at-once: a full history can mean
 * many hundreds of shows, each several TheTVDB HTTP calls - confirmed
 * empirically to reliably outlast Apache's own 60s reverse-proxy timeout.
 * processBatch() stops itself well before that and reports which shows/
 * lists/movies it got through, so Api\Controller\Import\TvTimeProcess can
 * call it again on the next cron tick to keep going from where it left off.
 * Lists only start once every show is finished (shows are the far larger,
 * more failure-prone piece); each individual list is small enough in
 * practice (a real export's largest list is a few dozen series) that it's
 * always either fully created in one go or not started at all - only
 * "which lists are already done" needs to survive across batches, not
 * partial progress within a single list. Movies only start once shows AND
 * lists are both finished, same "biggest/most TheTVDB-call-heavy piece
 * first" reasoning, and use their own name-based resume key
 * (Api\Model\TvTimeImport\MovieMatcher - there's no TheTVDB id for a movie
 * in the export the way there is for a series).
 */
final class Processor
{

    private const int TIME_BUDGET_SECONDS = 45;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array{
     *     shows: array<int, array{archived: bool, removed: bool, created_at: ?string}>,
     *     watched: array<int, array<int, string>>,
     *     rewatches: array<int, array<int, array{cpt: int, at: string}>>,
     *     lists: array<string, array{name: string, created_at: string, series: array<int, string>}>,
     *     movies: array<string, array{expected_year: ?string, watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>}>
     * } $parsed
     * @param array<int>    $alreadyDoneShows  show ids a previous batch already handled
     * @param array<string> $alreadyDoneLists  list s_keys a previous batch already handled
     * @param array<string> $alreadyDoneMovies movie names a previous batch already handled
     * @return array{
     *     done_show_ids: array<int>,
     *     done_list_keys: array<string>,
     *     done_movie_keys: array<string>,
     *     shows_synced: int,
     *     shows_failed: array<int>,
     *     episodes_watched: int,
     *     episodes_rewatched: int,
     *     lists_created: int,
     *     list_series_added: int,
     *     movies_synced: int,
     *     movies_unmatched: array<string>,
     *     movies_watched: int,
     *     movies_rewatched: int,
     *     finished: bool
     * }
     */
    public function processBatch(int $idUser, array $parsed, array $alreadyDoneShows, array $alreadyDoneLists, array $alreadyDoneMovies): array
    {
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;

        [$doneShowIds, $showsSynced, $showsFailed, $episodesWatched, $episodesRewatched, $showsFinished]
            = $this->processShows($idUser, $parsed, $alreadyDoneShows, $deadline);

        $doneListKeys      = array();
        $listsCreated      = 0;
        $listSeriesAdded   = 0;
        $listsFinished     = true;
        // only start lists once every show is done - shows are the far
        // larger, more TheTVDB-call-heavy piece, and most list series are
        // already synced by the time shows finish anyway
        if ($showsFinished) {
            [$doneListKeys, $listsCreated, $listSeriesAdded, $listsFinished]
                = $this->processLists($idUser, $parsed, $alreadyDoneLists, $deadline);
        }

        $doneMovieKeys   = array();
        $moviesSynced    = 0;
        $moviesUnmatched = array();
        $moviesWatched   = 0;
        $moviesRewatched = 0;
        $moviesFinished  = true;
        // movies only start once shows AND lists are both done, same
        // "biggest piece first" reasoning as lists above
        if ($showsFinished && $listsFinished) {
            [$doneMovieKeys, $moviesSynced, $moviesUnmatched, $moviesWatched, $moviesRewatched, $moviesFinished]
                = $this->processMovies($idUser, $parsed, $alreadyDoneMovies, $deadline);
        }

        return array(
            'done_show_ids'      => $doneShowIds,
            'done_list_keys'     => $doneListKeys,
            'done_movie_keys'    => $doneMovieKeys,
            'shows_synced'       => $showsSynced,
            'shows_failed'       => $showsFailed,
            'episodes_watched'   => $episodesWatched,
            'episodes_rewatched' => $episodesRewatched,
            'lists_created'      => $listsCreated,
            'list_series_added'  => $listSeriesAdded,
            'movies_synced'      => $moviesSynced,
            'movies_unmatched'   => $moviesUnmatched,
            'movies_watched'     => $moviesWatched,
            'movies_rewatched'   => $moviesRewatched,
            'finished'           => $showsFinished && $listsFinished && $moviesFinished,
        );
    }

    /**
     * @return array{0: array<int>, 1: int, 2: array<int>, 3: int, 4: int, 5: bool}
     */
    private function processShows(int $idUser, array $parsed, array $alreadyDone, float $deadline): array
    {
        $watchlist      = new Watchlist();
        $watchedEpisode = new WatchedEpisode();

        $doneShowIds       = array();
        $showsSynced       = 0;
        $showsFailed       = array();
        $episodesWatched   = 0;
        $episodesRewatched = 0;
        $finished          = true;

        foreach ($parsed['shows'] as $tvdbSeriesId => $flags) {
            if (in_array($tvdbSeriesId, $alreadyDone, true)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                $finished = false;
                break;
            }

            $info = (new Series())->sync($tvdbSeriesId, $this->client);
            if (empty($info)) {
                $showsFailed[] = $tvdbSeriesId;
                $doneShowIds[] = $tvdbSeriesId;
                continue;
            }

            $episodeRows = (new Episode())->syncForSeries($info['id_serie'], $tvdbSeriesId, $this->client);
            $watchlist->addFromImport($idUser, $info['id_serie'], $flags['archived'], $flags['removed'], $flags['created_at']);
            $showsSynced++;

            $idEpisodeByTvdbId = array_column($episodeRows, 'id_episode', 'tvdb_id');
            foreach ($parsed['watched'][$tvdbSeriesId] ?? array() as $tvdbEpisodeId => $watchedAt) {
                $idEpisode = $idEpisodeByTvdbId[$tvdbEpisodeId] ?? null;
                if ($idEpisode === null) {
                    // an episode TV Time knows about that this series' current
                    // TheTVDB episode list doesn't (renumbered/removed
                    // upstream since the export was made) - skip rather than
                    // fail the whole import over one stale reference
                    continue;
                }
                $watchedEpisode->markWatched($idUser, (int) $idEpisode, $watchedAt);
                $episodesWatched++;
            }

            // applied regardless of whether a base "first watch" was found
            // above for the same episode - TV Time's own cpt still means
            // "watched this many extra times", even when the export's
            // per-episode logs never captured the very first watch (see
            // Parser's own docblock on that gap)
            foreach ($parsed['rewatches'][$tvdbSeriesId] ?? array() as $tvdbEpisodeId => $rewatch) {
                $idEpisode = $idEpisodeByTvdbId[$tvdbEpisodeId] ?? null;
                if ($idEpisode === null) {
                    continue;
                }
                for ($i = 0; $i < $rewatch['cpt']; $i++) {
                    $watchedEpisode->markRewatched($idUser, (int) $idEpisode, $rewatch['at']);
                    $episodesRewatched++;
                }
            }

            $doneShowIds[] = $tvdbSeriesId;
        }

        return array($doneShowIds, $showsSynced, $showsFailed, $episodesWatched, $episodesRewatched, $finished);
    }

    /**
     * @return array{0: array<string>, 1: int, 2: int, 3: bool}
     */
    private function processLists(int $idUser, array $parsed, array $alreadyDone, float $deadline): array
    {
        $userList      = new UserList();
        $userListSerie = new UserListSerie();

        $doneListKeys    = array();
        $listsCreated    = 0;
        $listSeriesAdded = 0;
        $finished        = true;

        foreach ($parsed['lists'] as $sKey => $list) {
            if (in_array($sKey, $alreadyDone, true)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                $finished = false;
                break;
            }

            $idUserList = $userList->create($idUser, $list['name'], null, $list['created_at']);
            foreach ($list['series'] as $tvdbSeriesId => $addedAt) {
                $info = (new Series())->sync($tvdbSeriesId, $this->client);
                if (empty($info)) {
                    // a series TV Time once listed that TheTVDB no longer
                    // resolves - skip it, same tolerance as the shows phase
                    continue;
                }
                $userListSerie->add($idUserList, $info['id_serie'], $addedAt);
                $listSeriesAdded++;
            }
            $listsCreated++;

            $doneListKeys[] = $sKey;
        }

        return array($doneListKeys, $listsCreated, $listSeriesAdded, $finished);
    }

    /**
     * @return array{0: array<string>, 1: int, 2: array<string>, 3: int, 4: int, 5: bool}
     */
    private function processMovies(int $idUser, array $parsed, array $alreadyDone, float $deadline): array
    {
        $matcher        = new MovieMatcher($this->client);
        $movieWatchlist = new MovieWatchlist();
        $watchedMovie   = new WatchedMovie();

        $doneMovieKeys   = array();
        $moviesSynced    = 0;
        $moviesUnmatched = array();
        $moviesWatched   = 0;
        $moviesRewatched = 0;
        $finished        = true;

        foreach ($parsed['movies'] as $name => $entry) {
            if (in_array($name, $alreadyDone, true)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                $finished = false;
                break;
            }

            $tvdbId = $matcher->match($name, $entry['expected_year']);
            if ($tvdbId === null) {
                // no single confident match (unmatched title or an
                // ambiguous one TheTVDB can't disambiguate) - reported, not
                // guessed, same tolerance as a show TheTVDB no longer
                // resolves in processShows()
                $moviesUnmatched[] = $name;
                $doneMovieKeys[]   = $name;
                continue;
            }

            $info = (new Movie())->sync($tvdbId, $this->client);
            if (empty($info)) {
                $moviesUnmatched[] = $name;
                $doneMovieKeys[]   = $name;
                continue;
            }

            $movieWatchlist->addFromImport($idUser, $info['id_movie'], $entry['watchlist_created_at']);
            $moviesSynced++;

            if ($entry['watched_at'] !== null) {
                $watchedMovie->markWatched($idUser, $info['id_movie'], $entry['watched_at']);
                $moviesWatched++;
            }
            // each entry is one genuine extra watch with its own preserved
            // timestamp - see Parser's own docblock on why this is more
            // precise than processShows()'s rewatch handling (which only
            // ever has a bare count, no per-event date, for episodes)
            foreach ($entry['rewatch_at'] as $rewatchAt) {
                $watchedMovie->markRewatched($idUser, $info['id_movie'], $rewatchAt);
                $moviesRewatched++;
            }

            $doneMovieKeys[] = $name;
        }

        return array($doneMovieKeys, $moviesSynced, $moviesUnmatched, $moviesWatched, $moviesRewatched, $finished);
    }

}

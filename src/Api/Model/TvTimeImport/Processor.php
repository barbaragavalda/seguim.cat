<?php

namespace Api\Model\TvTimeImport;

use Api\Model\Episode;
use Api\Model\Movie;
use Api\Model\MovieImportPending;
use Api\Model\MovieWatchlist;
use Api\Model\Series;
use Api\Model\SeriesImportPending;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Api\Model\UserListSerie;
use Api\Model\WatchedEpisode;
use Api\Model\WatchedMovie;
use Api\Model\Watchlist;
use Webservice\Model\User;

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
 *
 * Safe to run more than once for the same account - each upload creates a
 * brand new Api\Model\TvTimeImport job with no memory of any earlier one
 * (a user re-exporting from TV Time, or just re-uploading, is a normal
 * thing to do), so every write this class makes is either a genuine upsert
 * (Watchlist/MovieWatchlist's own addFromImport(), WatchedEpisode/
 * WatchedMovie's own markWatched()) or has its own explicit dedup:
 * UserList lookup-or-create by TV Time's own list key (see
 * UserList::findByTvtimeKey()) instead of blindly creating a duplicate
 * list, and WatchedEpisode::syncRewatchesFromImport()/WatchedMovie::
 * syncRewatchFromImport() instead of a raw markRewatched() loop, which
 * would otherwise double-count every rewatch on a second import.
 */
final class Processor
{

    // well under any of the three separate limits a batch has to fit
    // inside: PHP's own max_execution_time (confirmed dev runs with this
    // unlimited - IS_DEV's php.ini sets 0 - which is exactly why this never
    // surfaced there; production's real limit was confirmed for real via
    // JobRunner's own register_shutdown_function catch - it was a hard 10s,
    // not the 45-then-20s this constant assumed, silently killing the
    // request mid-batch before recordBatch() ever got to run. Now raised on
    // Cdmon's own PHP config to 60s - see JobRunner::processOneBatch()'s
    // own set_time_limit() call for the matching safety-margin value),
    // Apache's own 60s reverse-proxy timeout, and a browser/CDN's own fetch
    // timeout. 40s leaves ~15s of headroom under the 55s script limit for
    // whichever TheTVDB call is in flight when the deadline is checked (a
    // single call can itself take up to CURLOPT_TIMEOUT's own 15s)
    private const int TIME_BUDGET_SECONDS = 40;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array{
     *     shows: array<int, array{archived: bool, removed: bool, created_at: ?string}>,
     *     watched: array<int, array<int, string>>,
     *     rewatches: array<int, array<int, array{cpt: int, at: string}>>,
     *     lists: array<string, array{name: string, created_at: string, series: array<int, string>, movies: array<string, string>}>,
     *     movies: array<string, array{expected_year: ?string, watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>}>,
     *     show_names: array<int, string>,
     *     episode_numbers: array<int, array<int, array{season: int, episode: int}>>
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
     *     shows_pending: int,
     *     episodes_watched: int,
     *     episodes_rewatched: int,
     *     lists_created: int,
     *     list_series_added: int,
     *     list_movies_added: int,
     *     list_series_pending: int,
     *     list_movies_pending: int,
     *     movies_synced: int,
     *     movies_unmatched: array<string>,
     *     movies_pending: int,
     *     movies_watched: int,
     *     movies_rewatched: int,
     *     finished: bool
     * }
     */
    public function processBatch(int $idUser, int $idTvtimeImport, array $parsed, array $alreadyDoneShows, array $alreadyDoneLists, array $alreadyDoneMovies): array
    {
        $deadline         = microtime(true) + self::TIME_BUDGET_SECONDS;
        // resolved once per batch (not once per matcher/candidate) - see
        // resolveTvdbLanguage()'s own docblock for why this needs a real
        // DB lookup rather than something already on hand here
        $tvdbLanguageCode = $this->resolveTvdbLanguage($idUser);

        [$doneShowIds, $showsSynced, $showsFailed, $showsPending, $episodesWatched, $episodesRewatched, $showsFinished]
            = $this->processShows($idUser, $idTvtimeImport, $parsed, $alreadyDoneShows, $deadline, $tvdbLanguageCode);

        $doneListKeys      = array();
        $listsCreated      = 0;
        $listSeriesAdded   = 0;
        $listMoviesAdded   = 0;
        $listSeriesPending = 0;
        $listMoviesPending = 0;
        $listsFinished     = true;
        // only start lists once every show is done - shows are the far
        // larger, more TheTVDB-call-heavy piece, and most list series are
        // already synced by the time shows finish anyway
        if ($showsFinished) {
            [$doneListKeys, $listsCreated, $listSeriesAdded, $listMoviesAdded, $listSeriesPending, $listMoviesPending, $listsFinished]
                = $this->processLists($idUser, $idTvtimeImport, $parsed, $alreadyDoneLists, $deadline, $tvdbLanguageCode);
        }

        $doneMovieKeys   = array();
        $moviesSynced    = 0;
        $moviesUnmatched = array();
        $moviesPending   = 0;
        $moviesWatched   = 0;
        $moviesRewatched = 0;
        $moviesFinished  = true;
        // movies only start once shows AND lists are both done, same
        // "biggest piece first" reasoning as lists above
        if ($showsFinished && $listsFinished) {
            [$doneMovieKeys, $moviesSynced, $moviesUnmatched, $moviesPending, $moviesWatched, $moviesRewatched, $moviesFinished]
                = $this->processMovies($idUser, $idTvtimeImport, $parsed, $alreadyDoneMovies, $deadline, $tvdbLanguageCode);
        }

        return array(
            'done_show_ids'      => $doneShowIds,
            'done_list_keys'     => $doneListKeys,
            'done_movie_keys'    => $doneMovieKeys,
            'shows_synced'       => $showsSynced,
            'shows_failed'       => $showsFailed,
            'shows_pending'      => $showsPending,
            'episodes_watched'   => $episodesWatched,
            'episodes_rewatched' => $episodesRewatched,
            'lists_created'      => $listsCreated,
            'list_series_added'  => $listSeriesAdded,
            'list_movies_added'  => $listMoviesAdded,
            'list_series_pending' => $listSeriesPending,
            'list_movies_pending' => $listMoviesPending,
            'movies_synced'      => $moviesSynced,
            'movies_unmatched'   => $moviesUnmatched,
            'movies_pending'     => $moviesPending,
            'movies_watched'     => $moviesWatched,
            'movies_rewatched'   => $moviesRewatched,
            'finished'           => $showsFinished && $listsFinished && $moviesFinished,
        );
    }

    /**
     * The importing user's own saved language preference (`user.
     * id_appacman_lang`, same field Api\Controller\Account\UpdateLanguage
     * writes) - a pending series/movie's candidate names should show up in
     * whatever language that user actually reads the app in, falling back
     * to English when TheTVDB has no translation for it, rather than
     * always showing English regardless (this is a background/cron-
     * triggered process, not a live request, so there's no
     * Core\Utils\Config::getLanguage() request-language to reuse - it has
     * to be looked up per user instead).
     */
    private function resolveTvdbLanguage(int $idUser): string
    {
        $user = new User();
        if (!$user->loadWithID($idUser)) {
            return 'eng';
        }
        $idAppacmanLang = $user->getInfo()['id_appacman_lang'] ?? null;
        if ($idAppacmanLang === null) {
            return 'eng';
        }
        return Languages::tvdbCode((int) $idAppacmanLang) ?? 'eng';
    }

    /**
     * @return array{0: array<int>, 1: int, 2: array<int>, 3: int, 4: int, 5: int, 6: bool}
     */
    private function processShows(int $idUser, int $idTvtimeImport, array $parsed, array $alreadyDone, float $deadline, string $tvdbLanguageCode): array
    {
        $watchlist      = new Watchlist();
        $watchedEpisode = new WatchedEpisode();
        $seriesMatcher  = new SeriesMatcher($this->client, $tvdbLanguageCode);
        $pendingImport  = new SeriesImportPending();

        $doneShowIds       = array();
        $showsSynced       = 0;
        $showsFailed       = array();
        $showsPending      = 0;
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
                $handled = $this->processRenamedShow(
                    $idUser,
                    $idTvtimeImport,
                    $tvdbSeriesId,
                    $flags,
                    $parsed,
                    $seriesMatcher,
                    $pendingImport,
                    $watchlist,
                    $watchedEpisode
                );
                if ($handled === null) {
                    // no show name survived anywhere in the export for this
                    // id - nothing to search TheTVDB with, genuinely a dead
                    // end (confirmed empirically: a couple of the user's own
                    // real shows_failed entries have no name anywhere)
                    $showsFailed[] = $tvdbSeriesId;
                } elseif ($handled['pending']) {
                    $showsPending++;
                } else {
                    $showsSynced++;
                    $episodesWatched   += $handled['episodes_watched'];
                    $episodesRewatched += $handled['episodes_rewatched'];
                }
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
            // Parser's own docblock on that gap). syncRewatchesFromImport()
            // rather than a raw markRewatched() loop - see its own docblock
            // on why a re-import (a separate, later job) needs this to stay
            // idempotent
            foreach ($parsed['rewatches'][$tvdbSeriesId] ?? array() as $tvdbEpisodeId => $rewatch) {
                $idEpisode = $idEpisodeByTvdbId[$tvdbEpisodeId] ?? null;
                if ($idEpisode === null) {
                    continue;
                }
                $episodesRewatched += $watchedEpisode->syncRewatchesFromImport(
                    $idUser,
                    (int) $idEpisode,
                    $rewatch['cpt'],
                    $rewatch['at'],
                    $idTvtimeImport
                );
            }

            // the user's own watch history - not TheTVDB's status, not TV
            // Time's own flags - decides whether a show counts as finished
            // for watch_later/stopped_watching purposes: once the last
            // aired regular episode is watched, deferring or having
            // stopped on it no longer makes sense
            if ($watchlist->hasWatchedLastEpisode($idUser, $info['id_serie'])) {
                $watchlist->setArchived($idUser, $info['id_serie'], false);
                $watchlist->setRemoved($idUser, $info['id_serie'], false);
            }

            $doneShowIds[] = $tvdbSeriesId;
        }

        return array($doneShowIds, $showsSynced, $showsFailed, $showsPending, $episodesWatched, $episodesRewatched, $finished);
    }

    /**
     * A show's own tvdb_id from the export came back empty from
     * Series::sync() - TheTVDB has renumbered/merged it since the export was
     * made (see SeriesMatcher's own docblock). Falls back to a name search:
     * a single confident match is synced and applied immediately, same as
     * the normal path; anything else (several same-titled candidates, or
     * none) is queued as a SeriesImportPending row instead of guessed.
     *
     * @param array{archived: bool, removed: bool, created_at: ?string} $flags
     * @return null|array{pending: bool, episodes_watched: int, episodes_rewatched: int}
     *         null if no show name survived anywhere in the export for this id
     */
    private function processRenamedShow(
        int $idUser,
        int $idTvtimeImport,
        int $oldTvdbSeriesId,
        array $flags,
        array $parsed,
        SeriesMatcher $seriesMatcher,
        SeriesImportPending $pendingImport,
        Watchlist $watchlist,
        WatchedEpisode $watchedEpisode
    ): ?array {
        $showName = $parsed['show_names'][$oldTvdbSeriesId] ?? null;
        if ($showName === null) {
            return null;
        }

        // already resolved (or dismissed) in an earlier import - see
        // Processor::processMovies()'s own comment on the identical check
        // there for why this has to happen before the matcher runs again
        if ($pendingImport->isResolved($idUser, $showName)) {
            return array('pending' => false, 'episodes_watched' => 0, 'episodes_rewatched' => 0);
        }

        $watchedEntries  = $this->toEpisodeNumberEntries($parsed['watched'][$oldTvdbSeriesId] ?? array(), $parsed['episode_numbers'][$oldTvdbSeriesId] ?? array());
        $rewatchEntries  = $this->toRewatchEpisodeNumberEntries($parsed['rewatches'][$oldTvdbSeriesId] ?? array(), $parsed['episode_numbers'][$oldTvdbSeriesId] ?? array());

        $result = $seriesMatcher->match($showName);
        if ($result['status'] !== 'matched') {
            $pendingImport->createOrUpdate($idUser, $idTvtimeImport, $showName, $flags, $watchedEntries, $rewatchEntries, $result['candidates']);
            return array('pending' => true, 'episodes_watched' => 0, 'episodes_rewatched' => 0);
        }

        $newTvdbSeriesId = $result['tvdb_id'];
        $info = (new Series())->sync($newTvdbSeriesId, $this->client);
        if (empty($info)) {
            // the match came from TheTVDB's own search a moment ago, so this
            // is a rare transient failure - still worth a pending entry
            // rather than losing the show's history outright
            $pendingImport->createOrUpdate($idUser, $idTvtimeImport, $showName, $flags, $watchedEntries, $rewatchEntries, array());
            return array('pending' => true, 'episodes_watched' => 0, 'episodes_rewatched' => 0);
        }

        $episodeRows = (new Episode())->syncForSeries($info['id_serie'], $newTvdbSeriesId, $this->client);
        $idEpisodeBySeasonEpisode = array();
        foreach ($episodeRows as $episodeRow) {
            $key = $episodeRow['season_number'] . '.' . $episodeRow['episode_number'];
            $idEpisodeBySeasonEpisode[$key] = $episodeRow['id_episode'];
        }

        $watchlist->addFromImport($idUser, $info['id_serie'], $flags['archived'], $flags['removed'], $flags['created_at']);

        $episodesWatched = 0;
        foreach ($watchedEntries as $entry) {
            $idEpisode = $idEpisodeBySeasonEpisode[$entry['season'] . '.' . $entry['episode']] ?? null;
            if ($idEpisode === null) {
                continue;
            }
            $watchedEpisode->markWatched($idUser, (int) $idEpisode, $entry['at']);
            $episodesWatched++;
        }

        $episodesRewatched = 0;
        foreach ($rewatchEntries as $entry) {
            $idEpisode = $idEpisodeBySeasonEpisode[$entry['season'] . '.' . $entry['episode']] ?? null;
            if ($idEpisode === null) {
                continue;
            }
            $episodesRewatched += $watchedEpisode->syncRewatchesFromImport(
                $idUser,
                (int) $idEpisode,
                $entry['cpt'],
                $entry['at'],
                $idTvtimeImport
            );
        }

        // same "user's own watch history decides finished, not TheTVDB's
        // status" reasoning as processShows() above
        if ($watchlist->hasWatchedLastEpisode($idUser, $info['id_serie'])) {
            $watchlist->setArchived($idUser, $info['id_serie'], false);
            $watchlist->setRemoved($idUser, $info['id_serie'], false);
        }

        return array('pending' => false, 'episodes_watched' => $episodesWatched, 'episodes_rewatched' => $episodesRewatched);
    }

    /**
     * @param array<int, string> $watched tvdb episode id => watched_at
     * @param array<int, array{season: int, episode: int}> $episodeNumbers tvdb episode id => {season, episode}
     * @return array<int, array{season: int, episode: int, at: string}>
     */
    private function toEpisodeNumberEntries(array $watched, array $episodeNumbers): array
    {
        $entries = array();
        foreach ($watched as $tvdbEpisodeId => $watchedAt) {
            $numbers = $episodeNumbers[$tvdbEpisodeId] ?? null;
            if ($numbers === null) {
                // no season/episode number survived for this one specific
                // episode anywhere in the export - can't be re-matched
                // against a different id's episode list, so it's dropped
                // rather than guessed
                continue;
            }
            $entries[] = array('season' => $numbers['season'], 'episode' => $numbers['episode'], 'at' => $watchedAt);
        }
        return $entries;
    }

    /**
     * @param array<int, array{cpt: int, at: string}> $rewatches tvdb episode id => {cpt, at}
     * @param array<int, array{season: int, episode: int}> $episodeNumbers tvdb episode id => {season, episode}
     * @return array<int, array{season: int, episode: int, cpt: int, at: string}>
     */
    private function toRewatchEpisodeNumberEntries(array $rewatches, array $episodeNumbers): array
    {
        $entries = array();
        foreach ($rewatches as $tvdbEpisodeId => $rewatch) {
            $numbers = $episodeNumbers[$tvdbEpisodeId] ?? null;
            if ($numbers === null) {
                continue;
            }
            $entries[] = array('season' => $numbers['season'], 'episode' => $numbers['episode'], 'cpt' => $rewatch['cpt'], 'at' => $rewatch['at']);
        }
        return $entries;
    }

    /**
     * @return array{0: array<string>, 1: int, 2: int, 3: int, 4: int, 5: int, 6: bool}
     */
    private function processLists(int $idUser, int $idTvtimeImport, array $parsed, array $alreadyDone, float $deadline, string $tvdbLanguageCode): array
    {
        $userList            = new UserList();
        $userListSerie       = new UserListSerie();
        $userListMovie       = new UserListMovie();
        $seriesMatcher       = new SeriesMatcher($this->client, $tvdbLanguageCode);
        $movieMatcher        = new MovieMatcher($this->client, $tvdbLanguageCode);
        $seriesPendingImport = new SeriesImportPending();
        $moviePendingImport  = new MovieImportPending();

        $doneListKeys      = array();
        $listsCreated      = 0;
        $listSeriesAdded   = 0;
        $listMoviesAdded   = 0;
        $listSeriesPending = 0;
        $listMoviesPending = 0;
        $finished          = true;

        foreach ($parsed['lists'] as $sKey => $list) {
            if (in_array($sKey, $alreadyDone, true)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                $finished = false;
                break;
            }

            // reuses the same list a previous, separate import job already
            // created for this exact TV Time list (matched via its own
            // stable s_key, not its name - see UserList::findByTvtimeKey()'s
            // own docblock on why) rather than creating a duplicate; a
            // brand new list still gets that key stored so a later import
            // can find it too
            $existingList = $userList->findByTvtimeKey($idUser, $sKey);
            $idUserList   = $existingList !== null
                ? (int) $existingList['id_user_list']
                : $userList->createFromImport($idUser, $list['name'], $sKey, $list['created_at']);

            foreach ($list['series'] as $tvdbSeriesId => $addedAt) {
                $info = (new Series())->sync($tvdbSeriesId, $this->client);
                if (!empty($info)) {
                    $userListSerie->add($idUserList, $info['id_serie'], $addedAt);
                    $listSeriesAdded++;
                    continue;
                }

                // dead/renumbered id - same phenomenon processShows()' own
                // processRenamedShow() recovers from, so give list series
                // the exact same name-search fallback instead of just
                // dropping them (this used to be the single biggest source
                // of "missing" list items: any show needing this recovery
                // vanished from every list it was in, even though the show
                // itself got fully recovered elsewhere in the same import)
                if ($this->resolveListSeriesByName($idUser, $idTvtimeImport, $tvdbSeriesId, $parsed, $seriesMatcher, $seriesPendingImport, $userListSerie, $idUserList, $addedAt)) {
                    $listSeriesAdded++;
                } else {
                    $listSeriesPending++;
                }
            }

            // a list movie has no TheTVDB id in the export (see Parser's own
            // docblock) - matched by name exactly like the main movie
            // import (reusing $parsed['movies']' own expected_year when this
            // same title is also tracked there, for the same disambiguation
            // MovieMatcher::match() already does elsewhere). An ambiguous/
            // unmatched result used to be silently left out of the list;
            // it's now linked to the same MovieImportPending row the main
            // movie import itself would create for this exact title, so
            // resolving it later (Api\Model\MovieImportPending::resolve())
            // adds it to this list too instead of just the general watchlist
            foreach ($list['movies'] as $movieName => $addedAt) {
                // already resolved (or dismissed) in an earlier import -
                // see Processor::processMovies()'s own comment on the
                // identical check there. Same "won't re-add to a brand new
                // list" caveat as resolveListSeriesByName()'s own comment
                if ($moviePendingImport->isResolved($idUser, $movieName)) {
                    continue;
                }

                $expectedYear = $parsed['movies'][$movieName]['expected_year'] ?? null;
                $result       = $movieMatcher->match($movieName, $expectedYear);
                if ($result['status'] === 'matched') {
                    $info = (new Movie())->sync($result['tvdb_id'], $this->client);
                    if (!empty($info)) {
                        $userListMovie->add($idUserList, $info['id_movie'], $addedAt);
                        $listMoviesAdded++;
                        continue;
                    }
                }

                $this->queueListMoviePending($idUser, $idTvtimeImport, $movieName, $expectedYear, $addedAt, $result['candidates'], $parsed, $moviePendingImport, $idUserList);
                $listMoviesPending++;
            }

            // recovers the movies Parser::parseListMeta() found via list
            // preview artwork but couldn't tie to a specific uuid/name (see
            // that method's own docblock) - a direct tvdb-id sync, same as
            // list series' own primary path just above, since there's no
            // ambiguity left to resolve here. UserListMovie::add() is a
            // no-op if $movieName's own match above already added this
            // exact movie, so this can't create a duplicate list entry.
            // No per-item added_at survives this recovery path, so the
            // list's own created_at is the closest available date
            foreach ($list['preview_movie_ids'] as $tvdbMovieId) {
                $info = (new Movie())->sync($tvdbMovieId, $this->client);
                if (!empty($info)) {
                    $userListMovie->add($idUserList, $info['id_movie'], $list['created_at']);
                    $listMoviesAdded++;
                }
            }

            $listsCreated++;
            $doneListKeys[] = $sKey;
        }

        return array($doneListKeys, $listsCreated, $listSeriesAdded, $listMoviesAdded, $listSeriesPending, $listMoviesPending, $finished);
    }

    /**
     * Recovers one list series whose own tvdb_id no longer resolves, same
     * name-search fallback as processRenamedShow() - a confident match is
     * synced and added to the list immediately; anything else queues the
     * show as pending for this list (reusing whatever SeriesImportPending
     * row already exists for this exact show_name - see createOrUpdate()'s
     * own upsert-by-name key - so a show that's ALSO a dead/renumbered
     * followed show only ever gets one pending row, just linked to more
     * lists) rather than dropping it.
     *
     * Deliberately reuses the very same pending row (and its resolve() flow)
     * a followed-but-dead show would get, even for a show that's list-only
     * and was never actually followed in TV Time - meaning resolving it
     * later also adds it to the general watchlist, not just this list. A
     * genuinely list-only show is rare in practice (TV Time's own UI mostly
     * surfaces already-tracked shows for adding to a list), and a second,
     * parallel "resolve into a list but not the watchlist" flow isn't worth
     * the added complexity for that edge case.
     *
     * @return bool true if the show ended up added to the list, false if
     *              it's now queued as pending instead
     */
    private function resolveListSeriesByName(
        int $idUser,
        int $idTvtimeImport,
        int $tvdbSeriesId,
        array $parsed,
        SeriesMatcher $seriesMatcher,
        SeriesImportPending $pendingImport,
        UserListSerie $userListSerie,
        int $idUserList,
        string $addedAt
    ): bool {
        $showName = $parsed['show_names'][$tvdbSeriesId] ?? null;

        // already resolved (or dismissed) in an earlier import - see
        // Processor::processMovies()'s own comment on the identical check
        // there. Doesn't re-add to *this* list if it's a new one since that
        // resolution (resolve() only links whichever lists were linked to
        // the pending row at the time) - rare enough (a list-only reference
        // to an already-resolved dead-id show, added to a brand new list on
        // a later import) not to chase further
        if ($showName !== null && $pendingImport->isResolved($idUser, $showName)) {
            return false;
        }

        if ($showName !== null) {
            $result = $seriesMatcher->match($showName);
            if ($result['status'] === 'matched') {
                $info = (new Series())->sync($result['tvdb_id'], $this->client);
                if (!empty($info)) {
                    $userListSerie->add($idUserList, (int) $info['id_serie'], $addedAt);
                    return true;
                }
            }
        }

        if ($showName === null) {
            // no show name survived anywhere in the export for this id -
            // nothing to search TheTVDB with, and no name to key a pending
            // row on either (its own unique key is id_user+show_name) -
            // genuinely nothing more can be done for this one
            return false;
        }

        $flags          = $parsed['shows'][$tvdbSeriesId] ?? array('archived' => false, 'removed' => false, 'created_at' => null);
        $watchedEntries = $this->toEpisodeNumberEntries($parsed['watched'][$tvdbSeriesId] ?? array(), $parsed['episode_numbers'][$tvdbSeriesId] ?? array());
        $rewatchEntries = $this->toRewatchEpisodeNumberEntries($parsed['rewatches'][$tvdbSeriesId] ?? array(), $parsed['episode_numbers'][$tvdbSeriesId] ?? array());
        $candidates     = ($result['status'] ?? null) === 'ambiguous' ? $result['candidates'] : array();

        $pendingImport->createOrUpdate($idUser, $idTvtimeImport, $showName, $flags, $watchedEntries, $rewatchEntries, $candidates);
        $pending = $pendingImport->idForShowName($idUser, $showName);
        if ($pending !== null) {
            $pendingImport->linkList($pending, $idUserList, $addedAt);
        }

        return false;
    }

    /**
     * Queues a list movie MovieMatcher couldn't confidently resolve as
     * pending, linked to $idUserList - see resolveListSeriesByName()'s own
     * docblock, identical reasoning (reuses whatever MovieImportPending row
     * already exists for this exact title). $parsed['movies'] is checked
     * for this same title's own watchlist/watched/rewatch snapshot - it's
     * very often also tracked there independently of being listed, and
     * without this the pending row would start out empty (no watch
     * history) until Processor::processMovies() itself gets around to
     * correcting it, possibly not until a later batch - a real risk of the
     * user resolving it from the app in between and losing that history
     *
     * @param array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}> $candidates
     */
    private function queueListMoviePending(
        int $idUser,
        int $idTvtimeImport,
        string $movieName,
        ?string $expectedYear,
        string $addedAt,
        array $candidates,
        array $parsed,
        MovieImportPending $pendingImport,
        int $idUserList
    ): void {
        $entry = $parsed['movies'][$movieName] ?? array('watchlist_created_at' => null, 'watched_at' => null, 'rewatch_at' => array());
        $pendingImport->createOrUpdate($idUser, $idTvtimeImport, $movieName, $expectedYear, $entry, $candidates);

        $pending = $pendingImport->idForMovieName($idUser, $movieName);
        if ($pending !== null) {
            $pendingImport->linkList($pending, $idUserList, $addedAt);
        }
    }

    /**
     * @return array{0: array<string>, 1: int, 2: array<string>, 3: int, 4: int, 5: int, 6: bool}
     */
    private function processMovies(int $idUser, int $idTvtimeImport, array $parsed, array $alreadyDone, float $deadline, string $tvdbLanguageCode): array
    {
        $matcher        = new MovieMatcher($this->client, $tvdbLanguageCode);
        $movieWatchlist = new MovieWatchlist();
        $watchedMovie   = new WatchedMovie();
        $pendingImport  = new MovieImportPending();

        $doneMovieKeys   = array();
        $moviesSynced    = 0;
        $moviesUnmatched = array();
        $moviesPending   = 0;
        $moviesWatched   = 0;
        $moviesRewatched = 0;
        $finished        = true;

        foreach ($parsed['movies'] as $key => $entry) {
            if (in_array($key, $alreadyDone, true)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                $finished = false;
                break;
            }

            // $key is the resume/dedup key - Parser::parseMovies() gives a
            // same-titled-but-different-film entry a disambiguated key
            // ("Title (Year)") so it doesn't collide with another movie of
            // the same title, but the actual title to search/show the user
            // is always $entry['name']
            $name = $entry['name'];

            // already resolved (or dismissed) in an earlier import - the
            // title is exactly as ambiguous now as it was then, so matching
            // it again would only re-derive the same pending question the
            // user already answered, see MovieImportPending's own docblock
            if ($pendingImport->isResolved($idUser, $name)) {
                $doneMovieKeys[] = $key;
                continue;
            }

            $result = $matcher->match($name, $entry['expected_year']);
            if ($result['status'] !== 'matched') {
                // not a single confident match (unmatched title, or several
                // same-titled TheTVDB movies with no way to tell them apart)
                // - never guessed, stored for the user to resolve by hand
                // later instead (see Api\Model\MovieImportPending and
                // MovieMatcher's own docblock)
                $pendingImport->createOrUpdate(
                    $idUser,
                    $idTvtimeImport,
                    $name,
                    $entry['expected_year'],
                    $entry,
                    $result['candidates']
                );
                $moviesUnmatched[] = $name;
                $moviesPending++;
                $doneMovieKeys[]   = $key;
                continue;
            }

            $info = (new Movie())->sync($result['tvdb_id'], $this->client);
            if (empty($info)) {
                // the match came from TheTVDB's own search a moment ago, so
                // this is a rare transient failure rather than a bad id -
                // still worth a pending entry (with no stored candidates,
                // since the one candidate that mattered just failed to
                // sync) rather than losing the title outright
                $pendingImport->createOrUpdate($idUser, $idTvtimeImport, $name, $entry['expected_year'], $entry, array());
                $moviesUnmatched[] = $name;
                $moviesPending++;
                $doneMovieKeys[]   = $key;
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
            // ever has a bare count, no per-event date, for episodes).
            // syncRewatchFromImport() rather than a raw markRewatched() call
            // - see its own docblock on why a re-import needs this to stay
            // idempotent
            foreach ($entry['rewatch_at'] as $rewatchAt) {
                if ($watchedMovie->syncRewatchFromImport($idUser, $info['id_movie'], $rewatchAt, $idTvtimeImport)) {
                    $moviesRewatched++;
                }
            }

            $doneMovieKeys[] = $key;
        }

        return array($doneMovieKeys, $moviesSynced, $moviesUnmatched, $moviesPending, $moviesWatched, $moviesRewatched, $finished);
    }

}

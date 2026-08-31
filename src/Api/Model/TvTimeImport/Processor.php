<?php

namespace Api\Model\TvTimeImport;

use Api\Model\Episode;
use Api\Model\Movie;
use Api\Model\MovieFavorite;
use Api\Model\MovieImportPending;
use Api\Model\MovieWatchlist;
use Api\Model\Series;
use Api\Model\SerieFavorite;
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
 * Applies a parsed TV Time export (see Parser) to a real account: syncs each
 * show from TheTVDB, adds it to the watchlist, marks matched episodes
 * watched/rewatched, then recreates the account's custom lists.
 *
 * Resumable and time-boxed: a full history's TheTVDB calls reliably outlast
 * Apache's 60s reverse-proxy timeout. processBatch() stops itself before
 * that and reports progress, so JobRunner can call it again next batch.
 * Lists only start once shows finish, movies only once shows AND lists
 * finish - biggest/most TheTVDB-call-heavy piece first. Movies use a
 * name-based resume key (MovieMatcher) since there's no TheTVDB id for a
 * movie in the export.
 *
 * Safe to run more than once for the same account: every write is either a
 * genuine upsert or has explicit dedup (UserList::findByTvtimeKey() instead
 * of duplicating a list, syncRewatchesFromImport()/syncRewatchFromImport()
 * instead of a raw markRewatched() loop, which would double-count rewatches
 * on a second import).
 */
final class Processor
{

    // must fit under PHP's max_execution_time (see JobRunner::processOneBatch()'s set_time_limit()),
    // Apache's 60s reverse-proxy timeout, and a browser/CDN fetch timeout; 40s leaves ~15s headroom
    // for whichever TheTVDB call is in flight when the deadline is checked
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
        // only start lists once every show is done - shows are the larger, more TheTVDB-call-heavy piece
        if ($showsFinished) {
            [$doneListKeys, $listsCreated, $listSeriesAdded, $listMoviesAdded, $listSeriesPending, $listMoviesPending, $listsFinished]
                = $this->processLists($idUser, $parsed, $alreadyDoneLists, $deadline, $tvdbLanguageCode);
        }

        $doneMovieKeys   = array();
        $moviesSynced    = 0;
        $moviesUnmatched = array();
        $moviesPending   = 0;
        $moviesWatched   = 0;
        $moviesRewatched = 0;
        $moviesFinished  = true;
        // movies only start once shows AND lists are both done
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
     * No live request to read a language from here (background/cron), so this
     * looks up the user's saved `id_appacman_lang` instead, falling back to English.
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
                    // no show name survived anywhere in the export for this id - nothing to search TheTVDB with
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
                    // episode renumbered/removed upstream since the export was made - skip, don't fail the whole import
                    continue;
                }
                $watchedEpisode->markWatched($idUser, (int) $idEpisode, $watchedAt);
                $episodesWatched++;
            }

            // applied even without a base "first watch" above - cpt still means "extra watches"
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

            // the user's own watch history, not TheTVDB's status or TV Time's flags, decides
            // finished: once the last aired episode is watched, deferring/stopped no longer makes sense
            if ($watchlist->hasWatchedLastEpisode($idUser, $info['id_serie'])) {
                $watchlist->setArchived($idUser, $info['id_serie'], false);
                $watchlist->setRemoved($idUser, $info['id_serie'], false);
            }

            $doneShowIds[] = $tvdbSeriesId;
        }

        return array($doneShowIds, $showsSynced, $showsFailed, $showsPending, $episodesWatched, $episodesRewatched, $finished);
    }

    /**
     * The export's tvdb_id no longer resolves (see SeriesMatcher). Falls
     * back to a name search: a confident match is synced immediately;
     * anything else is queued as a SeriesImportPending row.
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

        // already resolved (or dismissed) in an earlier import - don't re-derive the same question
        if ($pendingImport->isResolved($idUser, $showName)) {
            return array('pending' => false, 'episodes_watched' => 0, 'episodes_rewatched' => 0);
        }

        $watchedEntries  = $this->toEpisodeNumberEntries($parsed['watched'][$oldTvdbSeriesId] ?? array(), $parsed['episode_numbers'][$oldTvdbSeriesId] ?? array());
        $rewatchEntries  = $this->toRewatchEpisodeNumberEntries($parsed['rewatches'][$oldTvdbSeriesId] ?? array(), $parsed['episode_numbers'][$oldTvdbSeriesId] ?? array());

        $result = $seriesMatcher->match($showName);
        if ($result['status'] !== 'matched') {
            $pendingImport->createOrUpdate($idUser, $showName, $flags, $watchedEntries, $rewatchEntries, $result['candidates']);
            return array('pending' => true, 'episodes_watched' => 0, 'episodes_rewatched' => 0);
        }

        $newTvdbSeriesId = $result['tvdb_id'];
        $info = (new Series())->sync($newTvdbSeriesId, $this->client);
        if (empty($info)) {
            // rare transient failure right after a successful match - still worth a pending entry
            $pendingImport->createOrUpdate($idUser, $showName, $flags, $watchedEntries, $rewatchEntries, array());
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
                // no season/episode number survived for this episode - can't be re-matched, so it's dropped
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
    private function processLists(int $idUser, array $parsed, array $alreadyDone, float $deadline, string $tvdbLanguageCode): array
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

            // TV Time's built-in favorites lists map to the heart-toggle favoriting
            // (SerieFavorite/MovieFavorite) instead of a real UserList
            if ($sKey === 'favorite-series' || $sKey === 'favorite-movies') {
                $this->processFavoritesList($idUser, $list, $parsed, $seriesMatcher, $movieMatcher);
                $doneListKeys[] = $sKey;
                continue;
            }

            // reuses the list a previous import already created for this s_key rather than duplicating it
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

                // dead/renumbered id - same name-search fallback as processRenamedShow(), instead of dropping it
                if ($this->resolveListSeriesByName($idUser, $tvdbSeriesId, $parsed, $seriesMatcher, $seriesPendingImport, $userListSerie, $idUserList, $addedAt)) {
                    $listSeriesAdded++;
                } else {
                    $listSeriesPending++;
                }
            }

            // a list movie has no TheTVDB id (see Parser's docblock) - matched by name like
            // the main movie import, reusing $parsed['movies']'s expected_year when tracked
            // there too. An unresolved match links to the same MovieImportPending row the
            // main movie import would create for this title, so resolving it later
            // (MovieImportPending::resolve()) adds it to this list too, not just the watchlist
            foreach ($list['movies'] as $movieName => $addedAt) {
                // already resolved/dismissed in an earlier import - see processMovies()'s identical check
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

                $this->queueListMoviePending($idUser, $movieName, $expectedYear, $addedAt, $result['candidates'], $parsed, $moviePendingImport, $idUserList);
                $listMoviesPending++;
            }

            // recovers movies Parser::parseListMeta() found via preview artwork but couldn't tie
            // to a uuid/name - direct tvdb-id sync; UserListMovie::add() no-ops on a duplicate.
            // No per-item added_at survives this path, so the list's own created_at is used
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
     * Same direct-id-then-name-search recovery as a regular list
     * (resolveListSeriesByName()), but deliberately with no pending-resolution
     * fallback of its own: unlike a real list there's no screen to resolve
     * "some of my favorites" - an unresolved item is simply not favorited.
     */
    private function processFavoritesList(int $idUser, array $list, array $parsed, SeriesMatcher $seriesMatcher, MovieMatcher $movieMatcher): void
    {
        $serieFavorite = new SerieFavorite();
        $movieFavorite = new MovieFavorite();

        foreach ($list['series'] as $tvdbSeriesId => $addedAt) {
            $info = (new Series())->sync($tvdbSeriesId, $this->client);
            if (empty($info)) {
                $showName = $parsed['show_names'][$tvdbSeriesId] ?? null;
                $result   = $showName !== null ? $seriesMatcher->match($showName) : null;
                $info     = $result !== null && $result['status'] === 'matched'
                    ? (new Series())->sync($result['tvdb_id'], $this->client)
                    : array();
            }
            if (!empty($info)) {
                $serieFavorite->addFromImport($idUser, $info['id_serie'], $addedAt);
            }
        }

        foreach ($list['movies'] as $movieName => $addedAt) {
            $expectedYear = $parsed['movies'][$movieName]['expected_year'] ?? null;
            $result       = $movieMatcher->match($movieName, $expectedYear);
            if ($result['status'] === 'matched') {
                $info = (new Movie())->sync($result['tvdb_id'], $this->client);
                if (!empty($info)) {
                    $movieFavorite->addFromImport($idUser, $info['id_movie'], $addedAt);
                }
            }
        }

        foreach ($list['preview_movie_ids'] as $tvdbMovieId) {
            $info = (new Movie())->sync($tvdbMovieId, $this->client);
            if (!empty($info)) {
                $movieFavorite->addFromImport($idUser, $info['id_movie']);
            }
        }
    }

    /**
     * Recovers one list series whose tvdb_id no longer resolves, same
     * name-search fallback as processRenamedShow(). Reuses whatever
     * SeriesImportPending row already exists for this show_name
     * (createOrUpdate()'s upsert-by-name key), so a show that's also a
     * dead/renumbered followed show gets one pending row, linked to more
     * lists - meaning resolving it later adds it to the watchlist too, even
     * for a show that was list-only and never actually followed. A
     * separate "resolve into a list but not the watchlist" flow isn't
     * worth the complexity for that rare edge case.
     *
     * @return bool true if the show ended up added to the list, false if
     *              it's now queued as pending instead
     */
    private function resolveListSeriesByName(
        int $idUser,
        int $tvdbSeriesId,
        array $parsed,
        SeriesMatcher $seriesMatcher,
        SeriesImportPending $pendingImport,
        UserListSerie $userListSerie,
        int $idUserList,
        string $addedAt
    ): bool {
        $showName = $parsed['show_names'][$tvdbSeriesId] ?? null;

        // already resolved/dismissed in an earlier import (see processMovies()'s identical check) -
        // won't re-add to *this* list if it's new, since resolve() only links lists linked at the time
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
            // no name to search TheTVDB with or to key a pending row on (its unique key is id_user+show_name)
            return false;
        }

        $flags          = $parsed['shows'][$tvdbSeriesId] ?? array('archived' => false, 'removed' => false, 'created_at' => null);
        $watchedEntries = $this->toEpisodeNumberEntries($parsed['watched'][$tvdbSeriesId] ?? array(), $parsed['episode_numbers'][$tvdbSeriesId] ?? array());
        $rewatchEntries = $this->toRewatchEpisodeNumberEntries($parsed['rewatches'][$tvdbSeriesId] ?? array(), $parsed['episode_numbers'][$tvdbSeriesId] ?? array());
        $candidates     = $result['status'] === 'ambiguous' ? $result['candidates'] : array();

        $pendingImport->createOrUpdate($idUser, $showName, $flags, $watchedEntries, $rewatchEntries, $candidates);
        $pending = $pendingImport->idForShowName($idUser, $showName);
        if ($pending !== null) {
            $pendingImport->linkList($pending, $idUserList, $addedAt);
        }

        return false;
    }

    /**
     * Queues a list movie MovieMatcher couldn't resolve, linked to
     * $idUserList - reuses whatever MovieImportPending row already exists
     * for this title, same as resolveListSeriesByName(). $parsed['movies']
     * seeds the pending row with this title's own watch history (often
     * tracked independently of being listed) so it isn't blank until
     * processMovies() gets to it, possibly a later batch - avoiding a
     * window where the user resolves it and loses that history.
     *
     * @param array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}> $candidates
     */
    private function queueListMoviePending(
        int $idUser,
        string $movieName,
        ?string $expectedYear,
        string $addedAt,
        array $candidates,
        array $parsed,
        MovieImportPending $pendingImport,
        int $idUserList
    ): void {
        $entry = $parsed['movies'][$movieName] ?? array('watchlist_created_at' => null, 'watched_at' => null, 'rewatch_at' => array());
        $pendingImport->createOrUpdate($idUser, $movieName, $expectedYear, $entry, $candidates);

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

            // $key is the resume/dedup key (may be "Title (Year)"-disambiguated, see
            // Parser::parseMovies()); the title to search/show the user is always $entry['name']
            $name = $entry['name'];

            // already resolved/dismissed in an earlier import - re-matching would only
            // re-derive the same pending question the user already answered
            if ($pendingImport->isResolved($idUser, $name)) {
                $doneMovieKeys[] = $key;
                continue;
            }

            $result = $matcher->match($name, $entry['expected_year']);
            if ($result['status'] !== 'matched') {
                // never guessed - stored for the user to resolve by hand instead (see MovieMatcher's docblock)
                $pendingImport->createOrUpdate(
                    $idUser,
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
                // matched a moment ago, so this is a rare transient sync failure - still worth a pending entry
                $pendingImport->createOrUpdate($idUser, $name, $entry['expected_year'], $entry, array());
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
            // each entry is one genuine extra watch with its own timestamp (unlike
            // processShows()'s bare-count episode rewatches, see Parser's docblock)
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

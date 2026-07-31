<?php

namespace Api\Model\TvTimeImport;

use Generator;

/**
 * Parses a TV Time GDPR data export (see TvTimeImport, the export itself
 * isn't stored anywhere in this repo). TV Time's `tv_show_id`/`episode_id`
 * are TheTVDB's own ids directly - confirmed empirically (Twin Peaks'
 * tv_show_id 70533 and Nip/Tuck's S1E1-3 episode_ids 77022-77024 both match
 * TheTVDB exactly) - so no name-based fuzzy matching is needed once an id is
 * known.
 *
 * No single file in the export lists every watched episode - confirmed by
 * cross-checking against user_tv_show_data.csv's own nb_episodes_seen
 * count per show (e.g. Twin Peaks: 48). The union of WATCH_LOG_FILES plus
 * tracking-prod-records(.csv|-v2.csv)'s per-episode "watch" events recovers
 * 97.3% of all watched episodes (88% of shows exactly). What's still
 * missing (mostly very old history TV Time itself never logged
 * per-episode) simply isn't imported - there's no reliable way to guess
 * *which* specific episodes those were.
 *
 * Also returns `show_names` (tvdb series id => display name) and
 * `episode_numbers` (tvdb series id => tvdb episode id => {season, episode})
 * - unused by the normal happy path (a show/episode id from the export
 * resolves directly against TheTVDB there), but needed by
 * Processor::processShows()'s fallback for a show whose tvdb_id no longer
 * resolves at all (TheTVDB renumbered/merged it - see SeriesMatcher's own
 * docblock): the name lets it search TheTVDB for the new id, and the
 * season/episode numbers let watched history be re-matched against that new
 * id's own (necessarily different) episode ids, instead of the export's now-
 * dead ones. Built from whichever source rows happen to carry that
 * information alongside the id/name they're already being read for here -
 * confirmed empirically that season_number/episode_number ride along with
 * episode_id in nearly every watch-log source (tracking-prod-records(.csv|
 * -v2.csv), and every NAMED_WATCH_LOG_FILES entry except
 * show_seen_episode_latest.csv), so no extra file reads are needed.
 *
 * rewatched_episode.csv is parsed separately, into `rewatches` rather than
 * folded into `watched` - it's a *count* of extra watches beyond the
 * first (`cpt`, confirmed empirically: every row is a distinct episode_id,
 * never repeated, with values only ever 1 or 2), not a discrete per-event
 * log, and TheTVDB's own episode_id already tells the processor which
 * episode is *first* watched via `watched` - conflating the two would
 * double-count.
 *
 * Every source file carries its own `created_at` - preserved here (rather
 * than letting the importer stamp everything with "now") so the app's own
 * date-ordered views (Watchlist::listWatching()'s "most recently watched",
 * listNotStarted()'s "most recently added") stay meaningful for imported
 * data instead of every row tying for the same import timestamp. When the
 * same episode appears in more than one source with different timestamps,
 * the earliest one wins - the first time it was genuinely marked watched.
 *
 * lists-prod-lists.csv's own `objects` column isn't valid JSON - it's TV
 * Time's backend printing a Go `[]map[string]any` with `fmt.Sprint()`
 * (`"[map[created_at:1.7e+09 id:123 type:series] map[...] ...]"`), parsed
 * here with a regex rather than json_decode() (confirmed empirically: 446
 * objects across the real export's 27 lists, zero mismatches against a raw
 * substring count of "map["). Each object's own `created_at` is a Unix
 * timestamp (unlike every other file's plain datetime string) since that's
 * how TV Time's own backend serialized it.
 *
 * A movie entry inside a list has no `id` at all (confirmed empirically -
 * only `created_at`/`type`/`uuid`), unlike a series entry's direct TheTVDB
 * `id` - the same `uuid` scheme parseMovies() below already relies on to
 * group tracking-prod-records.csv's movie rows. $movieUuidNames (built once
 * per parse() call, from that same file) resolves a list movie's `uuid` to
 * its `movie_name`, which Processor::processLists() then feeds through
 * MovieMatcher exactly like the main movie import - a same-titled ambiguous
 * result is simply left out of the list (no pending-resolution entry for
 * *list membership* specifically - the movie itself still gets a proper
 * pending row from the main import if applicable, and can always be added
 * to the list by hand afterward once resolved).
 *
 * parseMovies() reads tracking-prod-records.csv's `entity_type=movie` rows
 * separately from the episode-watching logic above - a movie has no
 * TheTVDB id in the export at all (only `movie_name`+`release_date`, see
 * Api\Model\TvTimeImport\MovieMatcher), and its own rows follow a
 * genuinely different shape: each `uuid` there is one *tracked movie entry*,
 * not one event - `type` values sharing that uuid ('follow'/'towatch'/
 * 'watch'/'rewatch'/'rewatch_count') mark which buckets that single entry
 * currently belongs to, confirmed empirically by grouping the real export's
 * movie rows by uuid (e.g. a 'follow' row and a 'towatch' row sharing one
 * uuid have the exact same created_at - the same underlying entry, not two
 * events). Each distinct 'rewatch'-typed row for a uuid IS a genuine extra
 * watch with its own timestamp though (confirmed: the count of 'rewatch'
 * rows for a uuid always equals its final rewatch_count) - the separate
 * 'rewatch_count' rows are pure duplicates of the last 'rewatch' row (same
 * count, same timestamp) and are ignored here entirely.
 */
final class Parser
{

    /**
     * none of these have a tv_show_id column - only tv_show_name - so they
     * need $nameToId (built by parseFollowedShows() from followed_tv_show.csv
     * and user_tv_show_data.csv) to resolve one
     */
    private const array NAMED_WATCH_LOG_FILES = array(
        'seen_episode.csv',
        'seen_episode_latest.csv',
        'seen_episode_unitarian.csv',
        'seen_episode_source.csv',
        'watched_on_episode.csv',
        'show_seen_episode_latest.csv',
    );

    /**
     * a specific TV Time-side data corruption confirmed against a real
     * export: series/s_id 10000018 in both tracking-prod-records(.csv|
     * -v2.csv) carries watch-episode rows for at least 10 completely
     * unrelated shows (The Mike Douglas Show, Sturniolo Triplets, Kumovi,
     * Real Life Lore, Večera za 5, Filmhaus, The Five, Austin City Limits,
     * Lawmen: Bass Reeves, The Teacher) under one shared internal
     * series_uuid, nominally named "Kids React to" in its own
     * count-watch-episode-series summary row - none of these episodes are
     * real watch events (confirmed: every single one shares a created_at
     * within one of two few-minute windows, 2014-12-21 and 2015-01-06,
     * regardless of the show; a real user's viewing history across ~1000
     * shows is never this synchronized). The same corrupted episode_ids
     * also get a second, duplicate row under a real-looking series id for
     * several of the shows (e.g. s_id=70882 for "The Mike Douglas Show"
     * alongside 10000018) - collectCorruptedEpisodeIds() below drops both
     * copies wherever the episode_id appears, not just under this id.
     */
    private const string CORRUPTED_SERIES_ID = '10000018';

    /**
     * @return array{
     *     shows: array<int, array{archived: bool, removed: bool, created_at: ?string}>,
     *     watched: array<int, array<int, string>>,
     *     rewatches: array<int, array<int, array{cpt: int, at: string}>>,
     *     lists: array<string, array{name: string, created_at: string, series: array<int, string>, movies: array<string, string>, preview_movie_ids: array<int, int>}>,
     *     movies: array<string, array{expected_year: ?string, watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>}>,
     *     show_names: array<int, string>,
     *     episode_numbers: array<int, array<int, array{season: int, episode: int}>>
     * }
     */
    public function parse(string $dir): array
    {
        [$shows, $nameToId] = $this->parseFollowedShows($dir);
        $showNames           = array_flip($nameToId);
        $episodeNumbers      = array();
        $corruptedEpisodeIds = $this->collectCorruptedEpisodeIds(
            $dir . '/tracking-prod-records.csv',
            $dir . '/tracking-prod-records-v2.csv'
        );

        $watched = array();
        $this->mergeTrackingRecords($dir . '/tracking-prod-records.csv', $watched, $episodeNumbers, $showNames, $corruptedEpisodeIds);
        $this->mergeTrackingRecordsV2($dir . '/tracking-prod-records-v2.csv', $watched, $episodeNumbers, $showNames, $corruptedEpisodeIds);
        foreach (self::NAMED_WATCH_LOG_FILES as $file) {
            $this->mergeNamedWatchLog($dir . '/' . $file, $nameToId, $watched, $episodeNumbers);
        }

        $rewatches      = $this->parseRewatches($dir . '/rewatched_episode.csv', $nameToId, $episodeNumbers);
        $movieUuidNames = $this->parseMovieUuidNames($dir . '/tracking-prod-records.csv');
        $listMeta       = $this->parseListMeta($dir . '/lists-prod-lists.csv');
        $lists          = $this->parseLists($dir . '/lists-prod-lists.csv', $movieUuidNames, $listMeta['names'], $listMeta['previewMovieIds']);
        $lists          = $this->reorderLists($lists, $listMeta['order']);
        $movies         = $this->parseMovies($dir . '/tracking-prod-records.csv');

        // a show with watch history but no row in *either*
        // followed_tv_show.csv or user_tv_show_data.csv (parseFollowedShows()
        // above already covers a show present in just one of the two) - was
        // unfollowed/deleted in TV Time so long ago it dropped out of both,
        // or TV Time simply never carried it in those two files to begin
        // with (confirmed to happen: some real watch history only ever
        // shows up in the lower-level watch-log files, e.g.
        // tracking-prod-records-v2.csv, never in either tracking file).
        // Neither case is an explicit "the user stopped watching this"
        // signal (unlike followed_tv_show.csv's own `archived` or
        // user_tv_show_data.csv's own `is_followed=0`) - just missing
        // metadata - so `removed` is left false here rather than inferred:
        // still worth importing the history, added to the watchlist like
        // any other show, letting its own watch progress (not a guess)
        // decide whether it shows up as watching/finished. No follow date
        // survives for these (the row itself is gone), so created_at is
        // left null - the importer falls back to "now" for those
        // specifically.
        foreach (array_unique(array_merge(array_keys($watched), array_keys($rewatches))) as $tvdbId) {
            if (!isset($shows[$tvdbId])) {
                $shows[$tvdbId] = array('archived' => false, 'removed' => false, 'created_at' => null);
            }
        }

        return array(
            'shows'           => $shows,
            'watched'         => $watched,
            'rewatches'       => $rewatches,
            'lists'           => $lists,
            'movies'          => $movies,
            'show_names'      => $showNames,
            'episode_numbers' => $episodeNumbers,
        );
    }

    /**
     * followed_tv_show.csv turns out to be a log of follow *actions*, not
     * the show's current state - confirmed empirically against a real
     * export: 171 shows user_tv_show_data.csv's own `is_followed` still
     * marks as followed (82 of them with real watch history, e.g. "Widow's
     * Bay") are missing from followed_tv_show.csv entirely, and 285 it
     * marks `is_followed=0` (unfollowed) are still sitting in
     * followed_tv_show.csv looking active. `is_followed` is the one TV
     * Time itself would show as "still following" today, so it decides
     * `removed` here, not presence in followed_tv_show.csv.
     *
     * followed_tv_show.csv's own `archived` column turned out to mean
     * something else entirely than this app's `archived` ("veure més
     * tard"/watch later) - confirmed empirically against the same real
     * export: of the 98 shows TV Time marks archived there, *every single
     * one* has at least one watched episode (some in the hundreds - e.g.
     * "Polònia" 378, "The Voice" 365, "The Walking Dead" 156), and all 98
     * are still `is_followed=1`. That's not "haven't started this yet,
     * deliberately deferred" (this app's own `archived`) - it's "was
     * actively watching this, stopped, but never formally unfollowed it" -
     * exactly this app's own `removed` ("deixades de veure").
     *
     * The genuine "watch later" signal instead lives in
     * user_show_special_status.csv's own `status=for_later` rows (not
     * discovered until later - a previous version of this docblock
     * wrongly claimed no such field existed). Confirmed empirically
     * against the same real export: 412/431 (95.6%) of `for_later` shows
     * have zero watched episodes (`user_tv_show_data.csv`'s own
     * `nb_episodes_seen`), unlike `archived` above - genuinely "haven't
     * started, deliberately deferred". `for_later` disagrees with
     * `is_followed=0` far more often than `archived` does (235/431), but
     * almost always (230/235) for a show with zero watch history too -
     * not a real "stopped watching", just a show TV Time never actually
     * tracked as followed despite the user wanting to watch it later. So
     * `for_later` wins over the unfollowed-implies-`removed` rule *unless*
     * the show has real watch history, in which case `removed` still wins
     * (never observed together with TV Time's own `archived` in practice,
     * but that would win over both if it happened, being the more
     * explicit/authoritative signal of the two).
     *
     * "Stopped watching" only ever applies to a show with at least one
     * watched episode - the whole point of the flag is "started, then
     * stopped". A show that would otherwise read as `removed` (TV Time's
     * own `archived`, or unfollowed-and-not-`for_later`) but has zero
     * watched episodes becomes `archived` ("watch later") instead, same
     * bucket a genuine `for_later` show with no history gets - confirmed
     * against a real import that this genuinely happens (39 shows, not a
     * theoretical edge case).
     *
     * @return array{0: array<int, array{archived: bool, removed: bool, created_at: ?string}>, 1: array<string, int>}
     */
    private function parseFollowedShows(string $dir): array
    {
        $followed = array();
        $nameToId = array();
        foreach ($this->readCsv($dir . '/followed_tv_show.csv') as $row) {
            $tvdbId = (int) ($row['tv_show_id'] ?? 0);
            if ($tvdbId === 0) {
                continue;
            }
            $followed[$tvdbId] = array(
                'archived'   => ($row['archived'] ?? '0') === '1',
                'created_at' => ($row['created_at'] ?? '') !== '' ? $row['created_at'] : null,
            );
            if (($row['tv_show_name'] ?? '') !== '') {
                $nameToId[$row['tv_show_name']] = $tvdbId;
            }
        }

        $isFollowed = array();
        $hasWatched = array();
        foreach ($this->readCsv($dir . '/user_tv_show_data.csv') as $row) {
            $tvdbId = (int) ($row['tv_show_id'] ?? 0);
            if ($tvdbId === 0) {
                continue;
            }
            $isFollowed[$tvdbId] = ($row['is_followed'] ?? '0') === '1';
            $hasWatched[$tvdbId] = (int) ($row['nb_episodes_seen'] ?? 0) > 0;
            if (($row['tv_show_name'] ?? '') !== '' && !isset($nameToId[$row['tv_show_name']])) {
                $nameToId[$row['tv_show_name']] = $tvdbId;
            }
        }

        $forLater = array();
        foreach ($this->readCsv($dir . '/user_show_special_status.csv') as $row) {
            $tvdbId = (int) ($row['tv_show_id'] ?? 0);
            if ($tvdbId === 0 || ($row['status'] ?? '') !== 'for_later') {
                continue;
            }
            $forLater[$tvdbId] = true;
        }

        $shows = array();
        foreach (array_unique(array_merge(array_keys($followed), array_keys($isFollowed), array_keys($forLater))) as $tvdbId) {
            // the corrupted pseudo-series itself (see CORRUPTED_SERIES_ID's
            // own docblock) - present here too, e.g. user_tv_show_data.csv
            // carries its own bogus "Kids React to" row for it - excluded
            // entirely rather than let it resolve, by name search, to some
            // unrelated real show with a fabricated "stopped watching" mark
            if ((string) $tvdbId === self::CORRUPTED_SERIES_ID) {
                continue;
            }
            $unfollowed     = isset($isFollowed[$tvdbId]) ? !$isFollowed[$tvdbId] : false;
            $tvTimeArchived = $followed[$tvdbId]['archived'] ?? false;
            $wantsForLater  = $forLater[$tvdbId] ?? false;
            $everWatched    = $hasWatched[$tvdbId] ?? false;

            // stopping something implies having started it - a show that
            // would otherwise read as "stopped watching" (TV Time's own
            // archived, or unfollowed-and-not-explicitly-for_later) but has
            // zero watched episodes doesn't fit that meaning at all, so it
            // becomes "watch later" instead, the same bucket a genuine
            // for_later show with no watch history gets
            $wouldStopWatching = $tvTimeArchived || ($unfollowed && !$wantsForLater);

            $shows[$tvdbId] = array(
                'archived'   => !$everWatched && ($wantsForLater || $wouldStopWatching),
                'removed'    => $everWatched && $wouldStopWatching,
                'created_at' => $followed[$tvdbId]['created_at'] ?? null,
            );
        }

        return array($shows, $nameToId);
    }

    /**
     * @param array<int, array<int, string>> $watched
     * @param array<int, array<int, array{season: int, episode: int}>> $episodeNumbers
     * @param array<int, string> $showNames
     * @param array<int, bool> $corruptedEpisodeIds see collectCorruptedEpisodeIds()
     */
    private function mergeTrackingRecords(string $path, array &$watched, array &$episodeNumbers, array &$showNames, array $corruptedEpisodeIds): void
    {
        foreach ($this->readCsv($path) as $row) {
            if (($row['type'] ?? '') !== 'watch' || ($row['entity_type'] ?? '') !== 'episode') {
                continue;
            }
            $seriesId  = (int) ($row['series_id'] ?? 0);
            $episodeId = (int) ($row['episode_id'] ?? 0);
            if ($seriesId === 0 || $episodeId === 0 || isset($corruptedEpisodeIds[$episodeId])) {
                continue;
            }
            $this->recordWatch($watched, $seriesId, $episodeId, $row['created_at'] ?? null);
            $this->recordEpisodeNumber($episodeNumbers, $seriesId, $episodeId, $row['season_number'] ?? null, $row['episode_number'] ?? null);
            $this->recordShowName($showNames, $seriesId, $row['series_name'] ?? null);
        }
    }

    /**
     * @param array<int, array<int, string>> $watched
     * @param array<int, array<int, array{season: int, episode: int}>> $episodeNumbers
     * @param array<int, string> $showNames
     * @param array<int, bool> $corruptedEpisodeIds see collectCorruptedEpisodeIds()
     */
    private function mergeTrackingRecordsV2(string $path, array &$watched, array &$episodeNumbers, array &$showNames, array $corruptedEpisodeIds): void
    {
        foreach ($this->readCsv($path) as $row) {
            if (!str_starts_with($row['key'] ?? '', 'watch-episode-')) {
                continue;
            }
            $seriesId  = (int) ($row['s_id'] ?? 0);
            $episodeId = (int) ($row['ep_id'] ?? 0);
            if ($seriesId === 0 || $episodeId === 0 || isset($corruptedEpisodeIds[$episodeId])) {
                continue;
            }
            $this->recordWatch($watched, $seriesId, $episodeId, $row['created_at'] ?? null);
            $this->recordEpisodeNumber($episodeNumbers, $seriesId, $episodeId, $row['season_number'] ?? null, $row['episode_number'] ?? null);
            $this->recordShowName($showNames, $seriesId, $row['series_name'] ?? null);
        }
    }

    /**
     * Scans both tracking files once for CORRUPTED_SERIES_ID's own rows -
     * see that constant's own docblock - to build the set of episode_ids
     * to drop wherever they appear, including under a different,
     * real-looking series id (confirmed to happen: e.g. "The Mike Douglas
     * Show"'s own real tvdb_id also carries an exact duplicate of the same
     * bogus episode_id/created_at pair).
     *
     * @return array<int, bool> episode_id => true, for O(1) lookup
     */
    private function collectCorruptedEpisodeIds(string $trackingV1Path, string $trackingV2Path): array
    {
        $corrupted = array();
        foreach ($this->readCsv($trackingV1Path) as $row) {
            if (($row['series_id'] ?? '') === self::CORRUPTED_SERIES_ID && ($row['episode_id'] ?? '') !== '') {
                $corrupted[(int) $row['episode_id']] = true;
            }
        }
        foreach ($this->readCsv($trackingV2Path) as $row) {
            if (($row['s_id'] ?? '') === self::CORRUPTED_SERIES_ID && ($row['ep_id'] ?? '') !== '') {
                $corrupted[(int) $row['ep_id']] = true;
            }
        }
        return $corrupted;
    }

    /**
     * @param array<string, int>              $nameToId
     * @param array<int, array<int, string>>  $watched
     * @param array<int, array<int, array{season: int, episode: int}>> $episodeNumbers
     */
    private function mergeNamedWatchLog(string $path, array $nameToId, array &$watched, array &$episodeNumbers): void
    {
        foreach ($this->readCsv($path) as $row) {
            $tvdbId = isset($row['tv_show_id']) && $row['tv_show_id'] !== ''
                ? (int) $row['tv_show_id']
                : ($nameToId[$row['tv_show_name'] ?? ''] ?? null);
            $episodeId = (int) ($row['episode_id'] ?? 0);
            if ($tvdbId === null || $episodeId === 0) {
                continue;
            }
            $this->recordWatch($watched, $tvdbId, $episodeId, $row['created_at'] ?? null);
            // only present in most NAMED_WATCH_LOG_FILES, not
            // show_seen_episode_latest.csv - recordEpisodeNumber() no-ops
            // when either is missing/blank
            $this->recordEpisodeNumber($episodeNumbers, $tvdbId, $episodeId, $row['episode_season_number'] ?? null, $row['episode_number'] ?? null);
        }
    }

    /**
     * @param array<string, int> $nameToId
     * @param array<int, array<int, array{season: int, episode: int}>> $episodeNumbers
     * @return array<int, array<int, array{cpt: int, at: string}>>
     */
    private function parseRewatches(string $path, array $nameToId, array &$episodeNumbers): array
    {
        $rewatches = array();
        foreach ($this->readCsv($path) as $row) {
            $tvdbId    = $nameToId[$row['tv_show_name'] ?? ''] ?? null;
            $episodeId = (int) ($row['episode_id'] ?? 0);
            $cpt       = (int) ($row['cpt'] ?? 0);
            if ($tvdbId === null || $episodeId === 0 || $cpt <= 0) {
                continue;
            }
            $rewatches[$tvdbId][$episodeId] = array(
                'cpt' => $cpt,
                'at'  => ($row['created_at'] ?? '') !== '' ? $row['created_at'] : date('Y-m-d H:i:s'),
            );
            $this->recordEpisodeNumber($episodeNumbers, $tvdbId, $episodeId, $row['episode_season_number'] ?? null, $row['episode_number'] ?? null);
        }

        return $rewatches;
    }

    /**
     * first-seen wins, same as recordWatch() - every source agrees on a
     * given episode's season/episode number in practice, so this is just
     * about picking up the number from whichever source has it
     *
     * @param array<int, array<int, array{season: int, episode: int}>> $episodeNumbers
     */
    private function recordEpisodeNumber(array &$episodeNumbers, int $seriesId, int $episodeId, ?string $season, ?string $episode): void
    {
        if ($season === null || $season === '' || $episode === null || $episode === '') {
            return;
        }
        if (isset($episodeNumbers[$seriesId][$episodeId])) {
            return;
        }
        $episodeNumbers[$seriesId][$episodeId] = array('season' => (int) $season, 'episode' => (int) $episode);
    }

    /**
     * @param array<int, string> $showNames
     */
    private function recordShowName(array &$showNames, int $seriesId, ?string $name): void
    {
        if ($name === null || $name === '' || isset($showNames[$seriesId])) {
            return;
        }
        $showNames[$seriesId] = $name;
    }

    /**
     * @param array<string, string> $movieUuidNames uuid => movie_name, see this class' own docblock
     * @param array<string, string> $realNames s_key => real display name, see parseListMeta()
     * @param array<string, array<int, int>> $previewMovieIds s_key => tvdb movie ids, see parseListMeta()
     * @return array<string, array{name: string, created_at: string, series: array<int, string>, movies: array<string, string>, preview_movie_ids: array<int, int>}>
     */
    private function parseLists(string $path, array $movieUuidNames, array $realNames, array $previewMovieIds): array
    {
        $lists = array();
        foreach ($this->readCsv($path) as $row) {
            $sKey = $row['s_key'] ?? '';
            if ($sKey === '' || ($row['type'] ?? '') !== 'list') {
                continue;
            }

            $series = array();
            $movies = array();
            foreach ($this->parseListObjects($row['objects'] ?? '') as $object) {
                $objectType = $object['type'] ?? '';
                // per-item created_at is a Unix timestamp here, unlike the
                // list's own created_at column just below (a plain
                // datetime string, same as every other file)
                $addedAt = isset($object['created_at'])
                    ? date('Y-m-d H:i:s', (int) (float) $object['created_at'])
                    : date('Y-m-d H:i:s');

                if ($objectType === 'series') {
                    $tvdbId = (int) ($object['id'] ?? 0);
                    if ($tvdbId !== 0) {
                        $series[$tvdbId] = $addedAt;
                    }
                } elseif ($objectType === 'movie') {
                    $name = $movieUuidNames[$object['uuid'] ?? ''] ?? null;
                    // earliest wins, same "first time it was genuinely
                    // added" reasoning as recordWatch()
                    if ($name !== null && (!isset($movies[$name]) || $addedAt < $movies[$name])) {
                        $movies[$name] = $addedAt;
                    }
                }
            }
            if (empty($series) && empty($movies)) {
                continue;
            }

            $createdAt = ($row['created_at'] ?? '') !== '' ? $row['created_at'] : date('Y-m-d H:i:s');
            // the list's own `name` column is blank for most real lists
            // (confirmed empirically: 23 of 27) - $realNames (from the
            // export's own "collection" meta-row) is the actual display
            // name TV Time's own app shows the user, and is tried first
            $name = $realNames[$sKey] ?? trim($row['name'] ?? '');
            if ($name === '') {
                $name = 'List from ' . substr($createdAt, 0, 10);
            }

            $lists[$sKey] = array(
                'name'              => $name,
                'created_at'        => $createdAt,
                'series'            => $series,
                'movies'            => $movies,
                'preview_movie_ids' => $previewMovieIds[$sKey] ?? array(),
            );
        }

        return $lists;
    }

    /**
     * @return array<string, string> uuid => movie_name
     */
    private function parseMovieUuidNames(string $path): array
    {
        $names = array();
        foreach ($this->readCsv($path) as $row) {
            if (($row['entity_type'] ?? '') !== 'movie') {
                continue;
            }
            $uuid = $row['uuid'] ?? '';
            $name = trim($row['movie_name'] ?? '');
            if ($uuid === '' || $name === '' || isset($names[$uuid])) {
                continue;
            }
            $names[$uuid] = $name;
        }

        return $names;
    }

    /**
     * lists-prod-lists.csv carries a single extra row (`s_key = "collection"`,
     * `type` blank) whose own `lists` column is a *second*, differently-
     * shaped Go-`fmt.Sprint()` dump: one `map[...]` per list, each with its
     * own `s_key`/`name`/`posters`/`fanart`/... - this is where a list's real
     * display name actually lives (confirmed empirically: the per-list row's
     * own `name` column is blank for the vast majority of real lists, but
     * this index has a name for every one of them). Needs its own parser
     * (splitTopLevelMaps()/parseMapContent()) rather than parseListObjects()'s
     * simple regex, because these entries nest arrays inside themselves
     * (`posters:[url1 url2]`) - a naive `[^\]]*` match would stop at the
     * array's own closing `]`, truncating everything after it
     *
     * Also recovers a handful of otherwise-unidentifiable list movies from
     * that same `posters`/`fanart` preview: a movie-type object inside a
     * list's own `objects` column never carries anything beyond its `uuid`
     * (see this class' own docblock), but TheTVDB artwork URLs embed
     * TheTVDB's own movie id directly in their path (`v4/movie/{id}/...`, or
     * the older `movies/{id}/...` - never bare `posters/{id}-{n}.jpg`, which
     * is TheTVDB's *series* artwork scheme and deliberately not matched
     * here). Confirmed empirically against a real export: this preview is a
     * fixed, TV-Time-chosen snapshot capped at ~4 items regardless of the
     * list's real size, and not in creation order - so it can name a few
     * more movies for a given list, but never says *which* of that list's
     * still-unresolved uuids each one actually was. Processor::processLists()
     * therefore adds them to the list directly by tvdb id rather than trying
     * to slot them into a specific pending entry.
     *
     * `$order` is this same collection row's own entry order (TV Time's own
     * displayed list order, per the app itself), so a fresh import can
     * create the user's lists in that order instead of whatever order
     * lists-prod-lists.csv's separate per-list rows happen to appear in -
     * see parse()'s own reordering of $lists just below
     *
     * @return array{names: array<string, string>, previewMovieIds: array<string, array<int, int>>, order: array<int, string>}
     */
    private function parseListMeta(string $path): array
    {
        $names           = array();
        $previewMovieIds = array();
        $order           = array();
        foreach ($this->readCsv($path) as $row) {
            if (($row['s_key'] ?? '') !== 'collection') {
                continue;
            }
            foreach ($this->splitTopLevelMaps($row['lists'] ?? '') as $entry) {
                $kv   = $this->parseMapContent($entry);
                $sKey = $kv['s_key'] ?? '';
                if ($sKey === '') {
                    continue;
                }
                $order[] = $sKey;

                $name = trim($kv['name'] ?? '');
                if ($name !== '' && $name !== '<nil>') {
                    $names[$sKey] = $name;
                }

                $artwork = ($kv['posters'] ?? '') . ' ' . ($kv['fanart'] ?? '');
                if (preg_match_all('~(?:v4/movie|movies)/(\d+)/~', $artwork, $matches)) {
                    $previewMovieIds[$sKey] = array_values(array_unique(array_map('intval', $matches[1])));
                }
            }
            break;
        }

        return array('names' => $names, 'previewMovieIds' => $previewMovieIds, 'order' => $order);
    }

    /**
     * reorders $lists (built in lists-prod-lists.csv's own per-list-row
     * order) to match $order (the collection row's own entry order, TV
     * Time's real displayed order) instead - any list missing from $order
     * (shouldn't normally happen, both come from the same file) keeps its
     * original relative position, appended after every ordered one, so it's
     * never silently dropped
     *
     * @param array<string, array{name: string, created_at: string, series: array<int, string>, movies: array<string, string>, preview_movie_ids: array<int, int>}> $lists
     * @param array<int, string> $order
     * @return array<string, array{name: string, created_at: string, series: array<int, string>, movies: array<string, string>, preview_movie_ids: array<int, int>}>
     */
    private function reorderLists(array $lists, array $order): array
    {
        $reordered = array();
        foreach ($order as $sKey) {
            if (isset($lists[$sKey])) {
                $reordered[$sKey] = $lists[$sKey];
            }
        }
        foreach ($lists as $sKey => $list) {
            if (!isset($reordered[$sKey])) {
                $reordered[$sKey] = $list;
            }
        }

        return $reordered;
    }

    /**
     * splits a Go-printed `[map[...] map[...] ...]` blob into each map's raw
     * inner content, tracking bracket depth so a nested array inside one
     * entry (`posters:[...]`) doesn't get mistaken for that entry's own
     * closing bracket
     *
     * @return array<int, string>
     */
    private function splitTopLevelMaps(string $raw): array
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $raw = substr($raw, 1, -1);
        }

        $results = array();
        $i       = 0;
        $n       = strlen($raw);
        while ($i < $n) {
            $pos = strpos($raw, 'map[', $i);
            if ($pos === false) {
                break;
            }
            $start = $pos + 4;
            $depth = 1;
            $j     = $start;
            while ($j < $n && $depth > 0) {
                if ($raw[$j] === '[') {
                    $depth++;
                } elseif ($raw[$j] === ']') {
                    $depth--;
                }
                $j++;
            }
            $results[] = substr($raw, $start, $j - 1 - $start);
            $i         = $j;
        }

        return $results;
    }

    /**
     * splits one map[...]'s inner content into key => value pairs, the same
     * bracket-depth-aware way as splitTopLevelMaps() - a value can itself be
     * a nested array (`posters:[url1 url2]`), so a plain `explode(' ')` (as
     * parseListObjects() uses for the simpler, never-nested per-item
     * objects) would wrongly split it apart
     *
     * @return array<string, string>
     */
    private function parseMapContent(string $content): array
    {
        $keyStarts = array();
        $depth     = 0;
        $n         = strlen($content);
        for ($i = 0; $i < $n; $i++) {
            $c = $content[$i];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
            } elseif ($depth === 0 && $c === ':') {
                $j = $i - 1;
                while ($j >= 0 && (ctype_alnum($content[$j]) || $content[$j] === '_')) {
                    $j--;
                }
                $keyStart = $j + 1;
                if ($keyStart === 0 || $content[$keyStart - 1] === ' ') {
                    $keyStarts[] = array($keyStart, $i);
                }
            }
        }

        $kv    = array();
        $count = count($keyStarts);
        for ($idx = 0; $idx < $count; $idx++) {
            [$keyStart, $colonPos] = $keyStarts[$idx];
            $key                   = substr($content, $keyStart, $colonPos - $keyStart);
            $valueStart            = $colonPos + 1;
            $valueEnd              = $idx + 1 < $count ? $keyStarts[$idx + 1][0] - 1 : $n;
            $kv[$key]               = trim(substr($content, $valueStart, $valueEnd - $valueStart));
        }

        return $kv;
    }

    /**
     * parses TV Time's own Go `fmt.Sprint()` formatting of a
     * `[]map[string]any` - see this class's own docblock for why this
     * isn't just json_decode()
     *
     * @return array<int, array<string, string>>
     */
    private function parseListObjects(string $raw): array
    {
        if (!preg_match_all('/map\[([^\]]*)\]/', $raw, $matches)) {
            return array();
        }

        $objects = array();
        foreach ($matches[1] as $segment) {
            $object = array();
            foreach (explode(' ', $segment) as $token) {
                if (!str_contains($token, ':')) {
                    continue;
                }
                [$key, $value] = explode(':', $token, 2);
                $object[$key]  = $value;
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * see this class' own docblock for the uuid/type grouping this relies
     * on - each uuid is one tracked movie entry, not one event. Keyed by
     * name, EXCEPT when two different uuids share a name but disagree on
     * `expected_year` - the export has no tvdb id for movies, only a title,
     * so "A Star Is Born" (1937/1954/1976/2018 all share that exact title)
     * can't be told apart from a same-movie re-follow (a genuinely new
     * uuid for the same film, same year, after an unfollow) any other way.
     * A year mismatch means two different films, so that entry gets a
     * disambiguated key instead of silently merging into (and corrupting)
     * the first one - every entry still carries its own true `name`, since
     * the key itself is no longer reliable for that once disambiguated.
     *
     * @return array<string, array{name: string, expected_year: ?string, watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>}>
     */
    private function parseMovies(string $path): array
    {
        $rowsByUuid = array();
        foreach ($this->readCsv($path) as $row) {
            if (($row['entity_type'] ?? '') !== 'movie') {
                continue;
            }
            $uuid = $row['uuid'] ?? '';
            if ($uuid === '') {
                continue;
            }
            $rowsByUuid[$uuid][] = $row;
        }

        $movies = array();
        foreach ($rowsByUuid as $rows) {
            $name = trim($rows[0]['movie_name'] ?? '');
            $types = array_column($rows, 'type');
            // a lone 'rewatch_count' row with none of 'follow'/'towatch'/
            // 'watch' carries no actionable state at all (confirmed
            // empirically: a couple of real export rows are exactly this,
            // rewatch_count=0 with nothing else) - skip rather than import
            // an entry with no known status
            if ($name === '' || !array_intersect($types, array('follow', 'towatch', 'watch'))) {
                continue;
            }

            $releaseDate = $rows[0]['release_date'] ?? '';
            $year        = ($releaseDate !== '' && substr($releaseDate, 0, 4) !== '0001')
                ? substr($releaseDate, 0, 4)
                : null;

            $followRow = $this->firstRowOfType($rows, 'follow') ?? $this->firstRowOfType($rows, 'towatch');
            $watchRow  = $this->firstRowOfType($rows, 'watch');
            $rewatchAt = array_values(array_filter(array_map(
                fn(array $r): ?string => $r['type'] === 'rewatch' && ($r['created_at'] ?? '') !== '' ? $r['created_at'] : null,
                $rows
            )));
            sort($rewatchAt);

            $entry = array(
                'name'                  => $name,
                'expected_year'         => $year,
                'watchlist_created_at'  => $followRow['created_at'] ?? null,
                'watched_at'            => $watchRow['created_at'] ?? null,
                'rewatch_at'            => $rewatchAt,
            );

            $existing = $movies[$name] ?? null;
            if ($existing === null) {
                $movies[$name] = $entry;
                continue;
            }

            $sameFilm = $existing['expected_year'] === null
                || $entry['expected_year'] === null
                || $existing['expected_year'] === $entry['expected_year'];
            if (!$sameFilm) {
                // a different film with the exact same title - keep as its
                // own entry instead of corrupting $existing; a rare second
                // collision (three-plus films sharing one title) would
                // still overwrite here, but that's vanishingly unlikely in
                // one person's own tracked history
                $movies[$name . ' (' . $entry['expected_year'] . ')'] = $entry;
                continue;
            }

            // the same film, seen under a different uuid (unfollowed and
            // re-followed later, etc.) - merge rather than overwrite:
            // earliest watchlist/first-watch date wins, rewatch events are
            // combined, same "earliest wins" philosophy as recordWatch()
            // below
            $movies[$name] = array(
                'name'                 => $name,
                'expected_year'        => $existing['expected_year'] ?? $entry['expected_year'],
                'watchlist_created_at' => $this->earliest($existing['watchlist_created_at'], $entry['watchlist_created_at']),
                'watched_at'           => $this->earliest($existing['watched_at'], $entry['watched_at']),
                'rewatch_at'           => array_merge($existing['rewatch_at'], $entry['rewatch_at']),
            );
        }

        return $movies;
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function firstRowOfType(array $rows, string $type): ?array
    {
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === $type) {
                return $row;
            }
        }
        return null;
    }

    private function earliest(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return $a < $b ? $a : $b;
    }

    /**
     * keeps the earliest created_at seen for a given episode across every
     * source file - falls back to "now" only when a row genuinely has none
     *
     * @param array<int, array<int, string>> $watched
     */
    private function recordWatch(array &$watched, int $seriesId, int $episodeId, ?string $createdAt): void
    {
        $createdAt = $createdAt !== null && $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s');
        $existing  = $watched[$seriesId][$episodeId] ?? null;
        if ($existing === null || $createdAt < $existing) {
            $watched[$seriesId][$episodeId] = $createdAt;
        }
    }

    /**
     * @return Generator<array<string, string>>
     */
    private function readCsv(string $path): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        try {
            $header = fgetcsv($handle, escape: '');
            if ($header === false) {
                return;
            }
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }
                yield array_combine($header, $row);
            }
        } finally {
            fclose($handle);
        }
    }

}

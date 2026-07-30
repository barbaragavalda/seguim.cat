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
     * need $nameToId (built from followed_tv_show.csv) to resolve one
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
        [$shows, $nameToId] = $this->parseFollowedShows($dir . '/followed_tv_show.csv');
        $showNames           = array_flip($nameToId);
        $episodeNumbers      = array();

        $watched = array();
        $this->mergeTrackingRecords($dir . '/tracking-prod-records.csv', $watched, $episodeNumbers, $showNames);
        $this->mergeTrackingRecordsV2($dir . '/tracking-prod-records-v2.csv', $watched, $episodeNumbers, $showNames);
        foreach (self::NAMED_WATCH_LOG_FILES as $file) {
            $this->mergeNamedWatchLog($dir . '/' . $file, $nameToId, $watched, $episodeNumbers);
        }

        $rewatches      = $this->parseRewatches($dir . '/rewatched_episode.csv', $nameToId, $episodeNumbers);
        $movieUuidNames = $this->parseMovieUuidNames($dir . '/tracking-prod-records.csv');
        $listMeta       = $this->parseListMeta($dir . '/lists-prod-lists.csv');
        $lists          = $this->parseLists($dir . '/lists-prod-lists.csv', $movieUuidNames, $listMeta['names'], $listMeta['previewMovieIds']);
        $lists          = $this->reorderLists($lists, $listMeta['order']);
        $movies         = $this->parseMovies($dir . '/tracking-prod-records.csv');

        // a show with watch history but no row in followed_tv_show.csv at
        // all was unfollowed/deleted in TVTime at some point - still worth
        // importing the history, just flagged so it doesn't show up as an
        // active watchlist entry. No follow date survives for these (the
        // row itself is gone), so created_at is left null - the importer
        // falls back to "now" for those specifically.
        foreach (array_unique(array_merge(array_keys($watched), array_keys($rewatches))) as $tvdbId) {
            if (!isset($shows[$tvdbId])) {
                $shows[$tvdbId] = array('archived' => false, 'removed' => true, 'created_at' => null);
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
     * @return array{0: array<int, array{archived: bool, removed: bool, created_at: ?string}>, 1: array<string, int>}
     */
    private function parseFollowedShows(string $path): array
    {
        $shows    = array();
        $nameToId = array();
        foreach ($this->readCsv($path) as $row) {
            $tvdbId = (int) ($row['tv_show_id'] ?? 0);
            if ($tvdbId === 0) {
                continue;
            }
            $shows[$tvdbId] = array(
                'archived'   => ($row['archived'] ?? '0') === '1',
                'removed'    => false,
                'created_at' => ($row['created_at'] ?? '') !== '' ? $row['created_at'] : null,
            );
            if (($row['tv_show_name'] ?? '') !== '') {
                $nameToId[$row['tv_show_name']] = $tvdbId;
            }
        }

        return array($shows, $nameToId);
    }

    /**
     * @param array<int, array<int, string>> $watched
     * @param array<int, array<int, array{season: int, episode: int}>> $episodeNumbers
     * @param array<int, string> $showNames
     */
    private function mergeTrackingRecords(string $path, array &$watched, array &$episodeNumbers, array &$showNames): void
    {
        foreach ($this->readCsv($path) as $row) {
            if (($row['type'] ?? '') !== 'watch' || ($row['entity_type'] ?? '') !== 'episode') {
                continue;
            }
            $seriesId  = (int) ($row['series_id'] ?? 0);
            $episodeId = (int) ($row['episode_id'] ?? 0);
            if ($seriesId === 0 || $episodeId === 0) {
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
     */
    private function mergeTrackingRecordsV2(string $path, array &$watched, array &$episodeNumbers, array &$showNames): void
    {
        foreach ($this->readCsv($path) as $row) {
            if (!str_starts_with($row['key'] ?? '', 'watch-episode-')) {
                continue;
            }
            $seriesId  = (int) ($row['s_id'] ?? 0);
            $episodeId = (int) ($row['ep_id'] ?? 0);
            if ($seriesId === 0 || $episodeId === 0) {
                continue;
            }
            $this->recordWatch($watched, $seriesId, $episodeId, $row['created_at'] ?? null);
            $this->recordEpisodeNumber($episodeNumbers, $seriesId, $episodeId, $row['season_number'] ?? null, $row['episode_number'] ?? null);
            $this->recordShowName($showNames, $seriesId, $row['series_name'] ?? null);
        }
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
     * on - each uuid is one tracked movie entry, not one event
     *
     * @return array<string, array{expected_year: ?string, watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>}>
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
                'expected_year'        => $year,
                'watchlist_created_at' => $followRow['created_at'] ?? null,
                'watched_at'           => $watchRow['created_at'] ?? null,
                'rewatch_at'           => $rewatchAt,
            );

            // the same movie name already seen under a different uuid
            // (unfollowed and re-followed later, etc.) - merge rather than
            // overwrite: earliest watchlist/first-watch date wins, rewatch
            // events are combined, same "earliest wins" philosophy as
            // recordWatch() below
            $movies[$name] = !isset($movies[$name]) ? $entry : array(
                'expected_year'        => $movies[$name]['expected_year'] ?? $entry['expected_year'],
                'watchlist_created_at' => $this->earliest($movies[$name]['watchlist_created_at'], $entry['watchlist_created_at']),
                'watched_at'           => $this->earliest($movies[$name]['watched_at'], $entry['watched_at']),
                'rewatch_at'           => array_merge($movies[$name]['rewatch_at'], $entry['rewatch_at']),
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

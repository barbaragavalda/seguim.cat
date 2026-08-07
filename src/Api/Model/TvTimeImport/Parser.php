<?php

namespace Api\Model\TvTimeImport;

use Generator;

/**
 * Parses a TV Time GDPR data export (see TvTimeImport). `tv_show_id`/
 * `episode_id` are TheTVDB's own ids directly, so no name matching is
 * needed once an id is known.
 *
 * No single file lists every watched episode - the union of
 * NAMED_WATCH_LOG_FILES plus tracking-prod-records(.csv|-v2.csv) recovers
 * ~97% of them; the rest is old history TV Time never logged per-episode.
 *
 * `show_names`/`episode_numbers` are unused on the happy path; they feed
 * Processor::processShows()'s fallback for a show whose tvdb_id no longer
 * resolves (see SeriesMatcher) - name re-searches TheTVDB, season/episode
 * numbers re-match watch history to the new id.
 *
 * rewatched_episode.csv's `cpt` is a count of extra watches beyond the
 * first, not a per-event log, so it's kept separate in `rewatches` rather
 * than folded into `watched` (which would double-count the first watch).
 *
 * lists-prod-lists.csv's `objects` column isn't JSON - it's TV Time's Go
 * backend dump of a `[]map[string]any` via `fmt.Sprint()`, parsed here with
 * a regex. A movie entry inside it carries only a `uuid`, resolved via
 * $movieUuidNames (built from tracking-prod-records.csv) then matched
 * through MovieMatcher; an ambiguous result is left out of the list rather
 * than getting its own pending entry.
 *
 * parseMovies() reads tracking-prod-records.csv's `entity_type=movie` rows
 * separately - a movie has no TheTVDB id, only movie_name+release_date
 * (see MovieMatcher). Each `uuid` is one tracked movie entry;
 * 'follow'/'towatch'/'watch'/'rewatch_count' rows sharing a uuid are states
 * of that entry, not separate events - except 'rewatch' rows, each a
 * genuine extra watch with its own timestamp ('rewatch_count' rows just
 * duplicate the last 'rewatch' row and are ignored).
 */
final class Parser
{

    /** none of these have a tv_show_id column, only tv_show_name - needs $nameToId to resolve one */
    private const array NAMED_WATCH_LOG_FILES = array(
        'seen_episode.csv',
        'seen_episode_latest.csv',
        'seen_episode_unitarian.csv',
        'seen_episode_source.csv',
        'watched_on_episode.csv',
        'show_seen_episode_latest.csv',
    );

    /**
     * TV Time-side data corruption: this series id carries fabricated
     * watch-episode rows for ~10 unrelated "Kids React to" shows. The same
     * bogus episode_ids also duplicate under a real-looking series id for
     * some shows, so collectCorruptedEpisodeIds() drops them by episode_id
     * wherever they appear, not just under this id.
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

        // a show with watch history but no row in either followed/user_tv_show_data
        // file has no explicit "stopped watching" signal, so `removed` stays false
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
     * followed_tv_show.csv logs follow *actions*, not current state -
     * `removed` is decided by user_tv_show_data.csv's `is_followed` instead.
     *
     * followed_tv_show.csv's own `archived` doesn't mean this app's "watch
     * later": every show TV Time marks archived there already has watch
     * history, so it actually means this app's own `removed`. The real
     * "watch later" signal is user_show_special_status.csv's
     * `status=for_later`, which wins over the unfollowed-implies-`removed`
     * rule unless the show has real watch history, in which case `removed`
     * still wins.
     *
     * "Stopped watching" only applies with at least one watched episode -
     * a show that would otherwise read as `removed` but has none becomes
     * `archived` instead, same as a genuine `for_later` show with no history.
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
            // exclude the corrupted pseudo-series itself, see CORRUPTED_SERIES_ID
            if ((string) $tvdbId === self::CORRUPTED_SERIES_ID) {
                continue;
            }
            $unfollowed     = isset($isFollowed[$tvdbId]) ? !$isFollowed[$tvdbId] : false;
            $tvTimeArchived = $followed[$tvdbId]['archived'] ?? false;
            $wantsForLater  = $forLater[$tvdbId] ?? false;
            $everWatched    = $hasWatched[$tvdbId] ?? false;

            // stopping implies having started - with zero watched episodes this becomes "watch later" instead
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
     * builds the set of episode_ids to drop wherever they appear, see CORRUPTED_SERIES_ID
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
            // not present in show_seen_episode_latest.csv - recordEpisodeNumber() no-ops when missing/blank
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
     * first-seen wins - every source agrees on season/episode number in practice
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
                // per-item created_at is a Unix timestamp here, unlike the list's own created_at column below
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
                    if ($name !== null && (!isset($movies[$name]) || $addedAt < $movies[$name])) {
                        $movies[$name] = $addedAt;
                    }
                }
            }
            // a movie-only list whose movies were never followed/watched on their own has no uuid->name
            // path at all; $previewMovieIds is that list's other lead, so don't drop it just because $movies came up empty
            if (empty($series) && empty($movies) && empty($previewMovieIds[$sKey] ?? array())) {
                continue;
            }

            $createdAt = ($row['created_at'] ?? '') !== '' ? $row['created_at'] : date('Y-m-d H:i:s');
            // `name` is blank for most real lists - $realNames (from the export's "collection" meta-row) is tried first
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
     * The `s_key = "collection"` row's own `lists` column is a second,
     * differently-shaped Go dump (one `map[...]` per list) - this is where
     * a list's real display name lives, since the per-list row's own
     * `name` is blank for most real lists. Needs splitTopLevelMaps()/
     * parseMapContent() rather than parseListObjects()'s simple regex
     * because entries here nest arrays (`posters:[url1 url2]`).
     *
     * Also recovers list movies from the `posters`/`fanart` preview:
     * TheTVDB artwork URLs embed the movie id in their path, while a movie
     * object elsewhere in the export carries only a `uuid`. Capped at ~4
     * items and not in creation order, so Processor::processLists() adds
     * them by tvdb id directly rather than matching a pending entry.
     *
     * `$order` is TV Time's real displayed list order, used to reorder
     * $lists after the fact - see parse().
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
     * A list missing from $order keeps its original relative position, appended after the ordered ones.
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
     * Splits a Go-printed `[map[...] map[...] ...]` blob into each map's raw content,
     * tracking bracket depth so a nested array (`posters:[...]`) isn't mistaken for the close.
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
     * splits one map[...]'s inner content into key => value pairs, bracket-depth-aware like
     * splitTopLevelMaps() since a value can itself be a nested array (`posters:[url1 url2]`)
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
     * parses TV Time's Go `fmt.Sprint()` formatting of a `[]map[string]any`
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
     * Keyed by name, except when two uuids share a name but disagree on
     * `expected_year` - distinct films with the same title (e.g. "A Star
     * Is Born" 1937/1954/1976/2018) get a disambiguated key instead of merging.
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
            // a lone 'rewatch_count' row with none of follow/towatch/watch has no actionable state
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
                // a different film with the exact same title - keep as its own entry instead of corrupting $existing
                $movies[$name . ' (' . $entry['expected_year'] . ')'] = $entry;
                continue;
            }

            // same film under a different uuid (unfollowed/re-followed) - merge, earliest date wins
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
     * Keeps the earliest created_at seen for an episode across every source file.
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

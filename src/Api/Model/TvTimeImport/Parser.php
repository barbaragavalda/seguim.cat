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
 * substring count of "map["). Movie entries (no TheTVDB series id, only an
 * opaque `uuid`) are skipped - out of scope, same as everywhere else in
 * this app. Each object's own `created_at` is a Unix timestamp (unlike
 * every other file's plain datetime string) since that's how TV Time's own
 * backend serialized it.
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
     *     lists: array<string, array{name: string, created_at: string, series: array<int, string>}>
     * }
     */
    public function parse(string $dir): array
    {
        [$shows, $nameToId] = $this->parseFollowedShows($dir . '/followed_tv_show.csv');

        $watched = array();
        $this->mergeTrackingRecords($dir . '/tracking-prod-records.csv', $watched);
        $this->mergeTrackingRecordsV2($dir . '/tracking-prod-records-v2.csv', $watched);
        foreach (self::NAMED_WATCH_LOG_FILES as $file) {
            $this->mergeNamedWatchLog($dir . '/' . $file, $nameToId, $watched);
        }

        $rewatches = $this->parseRewatches($dir . '/rewatched_episode.csv', $nameToId);
        $lists     = $this->parseLists($dir . '/lists-prod-lists.csv');

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

        return array('shows' => $shows, 'watched' => $watched, 'rewatches' => $rewatches, 'lists' => $lists);
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
     */
    private function mergeTrackingRecords(string $path, array &$watched): void
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
        }
    }

    /**
     * @param array<int, array<int, string>> $watched
     */
    private function mergeTrackingRecordsV2(string $path, array &$watched): void
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
        }
    }

    /**
     * @param array<string, int>              $nameToId
     * @param array<int, array<int, string>>  $watched
     */
    private function mergeNamedWatchLog(string $path, array $nameToId, array &$watched): void
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
        }
    }

    /**
     * @param array<string, int> $nameToId
     * @return array<int, array<int, array{cpt: int, at: string}>>
     */
    private function parseRewatches(string $path, array $nameToId): array
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
        }

        return $rewatches;
    }

    /**
     * @return array<string, array{name: string, created_at: string, series: array<int, string>}>
     */
    private function parseLists(string $path): array
    {
        $lists = array();
        foreach ($this->readCsv($path) as $row) {
            $sKey = $row['s_key'] ?? '';
            if ($sKey === '' || ($row['type'] ?? '') !== 'list') {
                continue;
            }

            $series = array();
            foreach ($this->parseListObjects($row['objects'] ?? '') as $object) {
                $tvdbId = (int) ($object['id'] ?? 0);
                if (($object['type'] ?? '') !== 'series' || $tvdbId === 0) {
                    continue;
                }
                // per-item created_at is a Unix timestamp here, unlike the
                // list's own created_at column just below (a plain
                // datetime string, same as every other file)
                $series[$tvdbId] = isset($object['created_at'])
                    ? date('Y-m-d H:i:s', (int) (float) $object['created_at'])
                    : date('Y-m-d H:i:s');
            }
            if (empty($series)) {
                // movies-only (out of scope) or genuinely empty - nothing
                // importable, don't create a pointless blank list
                continue;
            }

            $createdAt = ($row['created_at'] ?? '') !== '' ? $row['created_at'] : date('Y-m-d H:i:s');
            $name      = trim($row['name'] ?? '');
            if ($name === '') {
                // most of a real export's lists turn out to be nameless -
                // confirmed empirically (23 of 27) - likely TV Time's own
                // auto-generated buckets rather than something the user
                // named themselves, but still worth importing (user's own
                // call) with a placeholder rather than silently blank
                $name = 'List from ' . substr($createdAt, 0, 10);
            }

            $lists[$sKey] = array('name' => $name, 'created_at' => $createdAt, 'series' => $series);
        }

        return $lists;
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

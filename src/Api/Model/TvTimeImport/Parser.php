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
 * 97.3% of all watched episodes (88% of shows exactly) - rewatched_episode.csv
 * is deliberately excluded (would double-count with the others; not the
 * "did I ever watch this" signal this importer cares about). What's still
 * missing (mostly very old history TV Time itself never logged
 * per-episode) simply isn't imported - there's no reliable way to guess
 * *which* specific episodes those were.
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
     *     shows: array<int, array{archived: bool, removed: bool}>,
     *     watched: array<int, array<int, true>>
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

        // a show with watch history but no row in followed_tv_show.csv at
        // all was unfollowed/deleted in TVTime at some point - still worth
        // importing the history, just flagged so it doesn't show up as an
        // active watchlist entry
        foreach (array_keys($watched) as $tvdbId) {
            if (!isset($shows[$tvdbId])) {
                $shows[$tvdbId] = array('archived' => false, 'removed' => true);
            }
        }

        return array('shows' => $shows, 'watched' => $watched);
    }

    /**
     * @return array{0: array<int, array{archived: bool, removed: bool}>, 1: array<string, int>}
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
            $shows[$tvdbId] = array('archived' => ($row['archived'] ?? '0') === '1', 'removed' => false);
            if (($row['tv_show_name'] ?? '') !== '') {
                $nameToId[$row['tv_show_name']] = $tvdbId;
            }
        }

        return array($shows, $nameToId);
    }

    /**
     * @param array<int, array<int, true>> $watched
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
            $watched[$seriesId][$episodeId] = true;
        }
    }

    /**
     * @param array<int, array<int, true>> $watched
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
            $watched[$seriesId][$episodeId] = true;
        }
    }

    /**
     * @param array<string, int>           $nameToId
     * @param array<int, array<int, true>> $watched
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
            $watched[$tvdbId][$episodeId] = true;
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

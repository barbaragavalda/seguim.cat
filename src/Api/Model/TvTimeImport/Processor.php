<?php

namespace Api\Model\TvTimeImport;

use Api\Model\Episode;
use Api\Model\Series;
use Api\Model\TheTvdb\Client;
use Api\Model\WatchedEpisode;
use Api\Model\Watchlist;

/**
 * Applies a parsed TV Time export (see Parser) to a real account: syncs
 * each show from TheTVDB using the same Series/Episode models the rest of
 * this app already uses (so there's no separate mirroring logic to keep in
 * sync), adds it to the watchlist with its archived/removed flags, and
 * marks every matched episode watched.
 *
 * Resumable and time-boxed rather than all-at-once: a full history can mean
 * many hundreds of shows, each several TheTVDB HTTP calls - confirmed
 * empirically to reliably outlast Apache's own 60s reverse-proxy timeout.
 * processBatch() stops itself well before that and reports which shows it
 * got through, so Api\Controller\Import\TvTimeProcess can call it again on
 * the next cron tick to keep going from where it left off.
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
     *     rewatches: array<int, array<int, array{cpt: int, at: string}>>
     * } $parsed
     * @param array<int> $alreadyDone show ids a previous batch already handled
     * @return array{
     *     done_show_ids: array<int>,
     *     shows_synced: int,
     *     shows_failed: array<int>,
     *     episodes_watched: int,
     *     episodes_rewatched: int,
     *     finished: bool
     * }
     */
    public function processBatch(int $idUser, array $parsed, array $alreadyDone): array
    {
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;

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

        return array(
            'done_show_ids'       => $doneShowIds,
            'shows_synced'        => $showsSynced,
            'shows_failed'        => $showsFailed,
            'episodes_watched'    => $episodesWatched,
            'episodes_rewatched'  => $episodesRewatched,
            'finished'            => $finished,
        );
    }

}

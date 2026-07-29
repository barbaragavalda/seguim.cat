<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\SeriesImportPending;
use Core\Routing\Attribute\Route;

/**
 * Shows from a TV Time import whose tv_show_id no longer resolves on
 * TheTVDB at all, and that Api\Model\TvTimeImport\SeriesMatcher's own name
 * search couldn't confidently resolve on its own - each with up to 5
 * TheTVDB candidates (name/image) for the app's own resolution screen. See
 * SeriesMatcher's own docblock for why this exists instead of guessing.
 */
#[Route('/import/series/pending', methods: ['GET'], name: 'api.import.series.pending')]
class PendingSeries extends Controller
{

    protected function run(): void
    {
        $pending = (new SeriesImportPending())->listForUser($this->user->getID());
        $this->assign(
            'pending',
            array_map(
                static fn(array $row): array => array(
                    'id'                     => (int) $row['id_series_import_pending'],
                    'show_name'              => $row['show_name'],
                    // lets the resolution screen show "X episodes watched"
                    // vs. a plain "in your watchlist", same distinction
                    // PendingMovies makes with watched/watched_at
                    'episodes_watched_count' => count(json_decode($row['watched_episodes'], true) ?? array()),
                    'candidates'             => $row['candidates'],
                ),
                $pending
            )
        );
    }

}

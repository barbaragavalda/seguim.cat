<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\SeriesImportPending;
use Core\Routing\Attribute\Route;

/**
 * Shows from a TV Time import whose tv_show_id no longer resolves on
 * TheTVDB, or that SeriesMatcher's name search couldn't confidently
 * resolve - each with up to 5 candidates for the app's resolution screen.
 * See SeriesMatcher's own docblock for why this exists.
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
                    'id'                     => (int) $row['id_user_serie_pending'],
                    'show_name'              => $row['show_name'],
                    // same watched-vs-watchlist distinction PendingMovies makes with watched_at
                    'episodes_watched_count' => count(json_decode($row['watched_episodes'], true) ?? array()),
                    'candidates'             => $row['candidates'],
                ),
                $pending
            )
        );
    }

}

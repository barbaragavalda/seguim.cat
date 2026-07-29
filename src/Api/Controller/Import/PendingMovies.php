<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\MovieImportPending;
use Core\Routing\Attribute\Route;

/**
 * Movie titles from a TV Time import that Api\Model\TvTimeImport\MovieMatcher
 * couldn't confidently resolve on its own - each with up to 5 TheTVDB
 * candidates (name/year/image) for the app's own resolution screen. See
 * MovieMatcher's own docblock for why this exists instead of guessing.
 */
#[Route('/import/movies/pending', methods: ['GET'], name: 'api.import.movies.pending')]
class PendingMovies extends Controller
{

    protected function run(): void
    {
        $pending = (new MovieImportPending())->listForUser($this->user->getID());
        $this->assign(
            'pending',
            array_map(
                static fn(array $row): array => array(
                    'id'            => (int) $row['id_movie_import_pending'],
                    'movie_name'    => $row['movie_name'],
                    'expected_year' => $row['expected_year'],
                    // lets the resolution screen show "watched on <date>"
                    // vs. "from your watchlist", same distinction the
                    // regular (already-matched) import path applies
                    'watched'       => $row['watched_at'] !== null,
                    'watched_at'    => $row['watched_at'],
                    'candidates'    => $row['candidates'],
                ),
                $pending
            )
        );
    }

}

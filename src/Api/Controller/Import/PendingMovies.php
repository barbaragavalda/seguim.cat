<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\MovieImportPending;
use Core\Routing\Attribute\Route;

/**
 * Movie titles from a TV Time import that MovieMatcher couldn't confidently
 * resolve - each with up to 5 TheTVDB candidates for the app's resolution
 * screen. See MovieMatcher's own docblock for why this exists.
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
                    'id'            => (int) $row['id_user_movie_pending'],
                    'movie_name'    => $row['movie_name'],
                    'expected_year' => $row['expected_year'],
                    // distinguishes "watched on <date>" vs "in your watchlist",
                    // same as the regular (already-matched) import path
                    'watched'       => $row['watched_at'] !== null,
                    'watched_at'    => $row['watched_at'],
                    'candidates'    => $row['candidates'],
                ),
                $pending
            )
        );
    }

}

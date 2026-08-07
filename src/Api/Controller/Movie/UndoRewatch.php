<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\WatchedMovie;
use Core\Routing\Attribute\Route;

/**
 * Inverse of Rewatch - collapses back to a single watch event. Unlike
 * Unwatch, doesn't fully unwatch - see WatchedMovie::resetToSingleWatch()
 */
#[Route('/movies/{tvdbId}/rewatch', methods: ['DELETE'], name: 'api.movie.undo_rewatch', requirements: ['tvdbId' => '\d+'])]
class UndoRewatch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new MovieModel();
        if ($movie->loadWithTvdbId($tvdbId)) {
            (new WatchedMovie())->resetToSingleWatch($this->user->getID(), $movie->getInfo()['id_movie']);
        }
    }

}

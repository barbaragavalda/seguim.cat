<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\WatchedMovie;
use Core\Routing\Attribute\Route;

/**
 * The inverse of Rewatch (POST /movies/{tvdbId}/rewatch) - collapses back
 * down to a single watch event rather than adding one. Unlike Unwatch
 * (DELETE /movies/{tvdbId}/watched), this doesn't fully unwatch the movie -
 * see WatchedMovie::resetToSingleWatch()
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

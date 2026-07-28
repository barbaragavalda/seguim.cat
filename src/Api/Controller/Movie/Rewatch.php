<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\MovieWatchlist;
use Api\Model\WatchedMovie;
use Core\Routing\Attribute\Route;

/**
 * Unlike Watch (POST /movies/{tvdbId}/watched), always records a new watch
 * event even if the movie is already watched - see
 * WatchedMovie::markRewatched(). This is what lets a movie be "guardada com
 * a vista múltiples vegades"
 */
#[Route('/movies/{tvdbId}/rewatch', methods: ['POST'], name: 'api.movie.rewatch', requirements: ['tvdbId' => '\d+'])]
class Rewatch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new MovieModel();
        if (!$movie->loadWithTvdbId($tvdbId)) {
            $this->error = '404';
            return;
        }

        // same reasoning as Watch - a rewatch implies tracking the movie
        (new MovieWatchlist())->add($this->user->getID(), $movie->getInfo()['id_movie']);

        (new WatchedMovie())->markRewatched($this->user->getID(), $movie->getInfo()['id_movie']);
    }

}

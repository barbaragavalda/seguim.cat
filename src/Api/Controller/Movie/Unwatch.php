<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\WatchedMovie;
use Core\Routing\Attribute\Route;

#[Route('/movies/{tvdbId}/watched', methods: ['DELETE'], name: 'api.movie.unwatch', requirements: ['tvdbId' => '\d+'])]
class Unwatch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new MovieModel();
        if ($movie->loadWithTvdbId($tvdbId)) {
            (new WatchedMovie())->markUnwatched($this->user->getID(), $movie->getInfo()['id_movie']);
        }
    }

}

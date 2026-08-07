<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\MovieWatchlist;
use Api\Model\WatchedMovie;
use Core\Routing\Attribute\Route;

#[Route('/movies/{tvdbId}/watched', methods: ['POST'], name: 'api.movie.watch', requirements: ['tvdbId' => '\d+'])]
class Watch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new MovieModel();
        if (!$movie->loadWithTvdbId($tvdbId)) {
            // movie isn't locally known yet - movie detail endpoint mirrors it first, same as Episode\Watch
            $this->error = '404';
            return;
        }

        // marking watched implies tracking it too - add() is INSERT IGNORE so this is a
        // no-op if already there, same reasoning as Episode\Watch
        (new MovieWatchlist())->add($this->user->getID(), $movie->getInfo()['id_movie']);

        (new WatchedMovie())->markWatched($this->user->getID(), $movie->getInfo()['id_movie']);
    }

}

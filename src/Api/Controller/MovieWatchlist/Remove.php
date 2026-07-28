<?php

namespace Api\Controller\MovieWatchlist;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\MovieWatchlist;
use Core\Routing\Attribute\Route;

#[Route('/movies/{tvdbId}/watchlist', methods: ['DELETE'], name: 'api.movie_watchlist.remove', requirements: ['tvdbId' => '\d+'])]
class Remove extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new Movie();
        if ($movie->loadWithTvdbId($tvdbId)) {
            (new MovieWatchlist())->remove($this->user->getID(), $movie->getInfo()['id_movie']);
        }
    }

}

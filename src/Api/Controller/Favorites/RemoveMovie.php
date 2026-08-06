<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\MovieFavorite;
use Core\Routing\Attribute\Route;

#[Route('/favorites/movies/{tvdbId}', methods: ['DELETE'], name: 'api.favorites.remove_movie', requirements: ['tvdbId' => '\d+'])]
class RemoveMovie extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new Movie();
        if (!$movie->loadWithTvdbId($tvdbId)) {
            return;
        }

        (new MovieFavorite())->remove($this->user->getID(), $movie->getInfo()['id_movie']);
    }

}

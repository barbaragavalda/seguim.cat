<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\SerieFavorite;
use Core\Routing\Attribute\Route;

#[Route('/favorites/series/{tvdbId}', methods: ['DELETE'], name: 'api.favorites.remove_serie', requirements: ['tvdbId' => '\d+'])]
class RemoveSerie extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $series = new Series();
        if (!$series->loadWithTvdbId($tvdbId)) {
            return;
        }

        (new SerieFavorite())->remove($this->user->getID(), $series->getInfo()['id_serie']);
    }

}

<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist/{tvdbId}', methods: ['DELETE'], name: 'api.watchlist.remove', requirements: ['tvdbId' => '\d+'])]
class Remove extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $series = new Series();
        if ($series->loadWithTvdbId($tvdbId)) {
            (new Watchlist())->remove($this->user->getID(), $series->getInfo()['id_serie']);
        }
    }

}

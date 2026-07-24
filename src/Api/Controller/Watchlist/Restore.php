<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist/{tvdbId}/removed', methods: ['DELETE'], name: 'api.watchlist.restore', requirements: ['tvdbId' => '\d+'])]
class Restore extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $series = new Series();
        if ($series->loadWithTvdbId($tvdbId)) {
            (new Watchlist())->setRemoved($this->user->getID(), $series->getInfo()['id_serie'], false);
        }
    }

}

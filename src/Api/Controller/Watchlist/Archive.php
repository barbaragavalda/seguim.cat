<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist/{tvdbId}/archived', methods: ['POST'], name: 'api.watchlist.archive', requirements: ['tvdbId' => '\d+'])]
class Archive extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $series = new Series();
        if ($series->loadWithTvdbId($tvdbId)) {
            (new Watchlist())->setArchived($this->user->getID(), $series->getInfo()['id_serie'], true);
        }
    }

}

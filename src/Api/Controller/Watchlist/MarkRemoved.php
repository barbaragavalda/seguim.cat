<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

/**
 * Not to be confused with Remove (DELETE /watchlist/{tvdbId}, a hard
 * delete) - this sets the same soft `removed` flag the TV Time importer
 * uses for a show no longer followed there, keeping the row and its watch
 * history but hiding it from both watchlist listings
 */
#[Route('/watchlist/{tvdbId}/removed', methods: ['POST'], name: 'api.watchlist.mark_removed', requirements: ['tvdbId' => '\d+'])]
class MarkRemoved extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $series = new Series();
        if ($series->loadWithTvdbId($tvdbId)) {
            (new Watchlist())->setRemoved($this->user->getID(), $series->getInfo()['id_serie'], true);
        }
    }

}

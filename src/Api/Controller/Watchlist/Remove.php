<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\Watchlist;
use Api\Model\WatchedEpisode;
use Core\Routing\Attribute\Route;

/**
 * Hard delete - unlike MarkRemoved, doesn't keep the row around. Only
 * allowed with no watch history: remove() deletes user_serie_watchlist but
 * not user_episode_watched, so with real history those rows would survive
 * orphaned and reappear as already-watched if the series were re-added.
 * A series with history should go through MarkRemoved instead.
 */
#[Route('/watchlist/{tvdbId}', methods: ['DELETE'], name: 'api.watchlist.remove', requirements: ['tvdbId' => '\d+'])]
class Remove extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $series = new Series();
        if (!$series->loadWithTvdbId($tvdbId)) {
            return;
        }

        $idSerie = $series->getInfo()['id_serie'];
        if ((new WatchedEpisode())->hasAnyWatched($this->user->getID(), $idSerie)) {
            $this->error = 'has_watch_history';
            return;
        }

        (new Watchlist())->remove($this->user->getID(), $idSerie);
    }

}

<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\Watchlist;
use Api\Model\WatchedEpisode;
use Core\Routing\Attribute\Route;

/**
 * Hard delete - unlike MarkRemoved (POST /watchlist/{tvdbId}/removed), this
 * doesn't keep the row around at all. Only allowed when the user has never
 * watched anything from the series: Watchlist::remove() only deletes the
 * user_serie_watchlist row, not user_episode_watched - with real watch
 * history, that history would silently survive as orphaned rows, ready to
 * reappear looking already-watched the moment the series was ever re-added.
 * A series with watch history should go through MarkRemoved ("deixar de
 * veure") instead, which keeps that history on purpose.
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

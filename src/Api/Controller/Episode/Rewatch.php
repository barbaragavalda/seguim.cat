<?php

namespace Api\Controller\Episode;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\WatchedEpisode;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

/**
 * Unlike Watch (POST /episode/{tvdbId}/watched), always records a new
 * watch event even if the episode is already watched - see
 * WatchedEpisode::markRewatched()
 */
#[Route('/episode/{tvdbId}/rewatch', methods: ['POST'], name: 'api.episode.rewatch', requirements: ['tvdbId' => '\d+'])]
class Rewatch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $episode = new Episode();
        if (!$episode->loadWithTvdbId($tvdbId)) {
            $this->error = '404';
            return;
        }

        $watchlist = new Watchlist();
        $idSerie   = $episode->getInfo()['id_serie'];

        // same reasoning as Watch - a rewatch implies following the series,
        // and just as much "veure més tard" (archived)'s own opposite
        $watchlist->add($this->user->getID(), $idSerie);
        $watchlist->setArchived($this->user->getID(), $idSerie, false);

        (new WatchedEpisode())->markRewatched($this->user->getID(), $episode->getInfo()['id_episode']);
    }

}

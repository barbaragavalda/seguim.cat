<?php

namespace Api\Controller\Episode;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\WatchedEpisode;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/episode/{tvdbId}/watched', methods: ['POST'], name: 'api.episode.watch', requirements: ['tvdbId' => '\d+'])]
class Watch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $episode = new Episode();
        if (!$episode->loadWithTvdbId($tvdbId)) {
            // episode isn't locally known yet - the series detail endpoint mirrors episodes first
            $this->error = '404';
            return;
        }

        // server-side backstop for the same rule the app's UI already enforces -
        // this is a real request boundary, so it can't just trust the UI
        $aired = $episode->getInfo()['aired'] ?? null;
        if ($aired === null || $aired > date('Y-m-d')) {
            $this->error = 'This episode has not aired yet.';
            return;
        }

        $watchlist = new Watchlist();
        $idSerie   = $episode->getInfo()['id_serie'];

        // implies the user follows this series even without an explicit "+
        // Watchlist" tap - add() is INSERT IGNORE, so a no-op if already there
        $watchlist->add($this->user->getID(), $idSerie);

        // "archived" means the user deliberately deferred the series; watching
        // an episode is the literal opposite, so it auto-clears (no-op if unset)
        $watchlist->setArchived($this->user->getID(), $idSerie, false);

        (new WatchedEpisode())->markWatched($this->user->getID(), $episode->getInfo()['id_episode']);
    }

}

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
            // episode isn't locally known yet - the series detail endpoint
            // is what mirrors episodes first, per this pass' lazy-mirror scope
            $this->error = '404';
            return;
        }

        $watchlist = new Watchlist();
        $idSerie   = $episode->getInfo()['id_serie'];

        // marking an episode watched implies the user is following this
        // series, even if they never explicitly hit "+ Watchlist" first -
        // add() is an INSERT IGNORE, so this is a no-op (and doesn't touch
        // the archived/removed flags) when the series is already there
        $watchlist->add($this->user->getID(), $idSerie);

        // "archived" ("veure més tard") means the user deliberately deferred
        // this series - watching an episode from it is the literal opposite
        // of that, so it clears on its own rather than leaving the user to
        // notice and un-archive by hand. A no-op UPDATE when it wasn't set.
        $watchlist->setArchived($this->user->getID(), $idSerie, false);

        (new WatchedEpisode())->markWatched($this->user->getID(), $episode->getInfo()['id_episode']);
    }

}

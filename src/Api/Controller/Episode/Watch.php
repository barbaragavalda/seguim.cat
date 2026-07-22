<?php

namespace Api\Controller\Episode;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\WatchedEpisode;
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

        (new WatchedEpisode())->markWatched($this->user->getID(), $episode->getInfo()['id_episode']);
    }

}

<?php

namespace Api\Controller\Episode;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\WatchedEpisode;
use Core\Routing\Attribute\Route;

#[Route('/episode/{tvdbId}/watched', methods: ['DELETE'], name: 'api.episode.unwatch', requirements: ['tvdbId' => '\d+'])]
class Unwatch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $episode = new Episode();
        if ($episode->loadWithTvdbId($tvdbId)) {
            (new WatchedEpisode())->markUnwatched($this->user->getID(), $episode->getInfo()['id_episode']);
        }
    }

}

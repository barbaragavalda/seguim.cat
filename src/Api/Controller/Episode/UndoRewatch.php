<?php

namespace Api\Controller\Episode;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\WatchedEpisode;
use Core\Routing\Attribute\Route;

/**
 * The inverse of Rewatch - collapses back to a single watch event rather
 * than adding one. Unlike Unwatch, doesn't fully unwatch the episode -
 * see WatchedEpisode::resetToSingleWatch()
 */
#[Route('/episode/{tvdbId}/rewatch', methods: ['DELETE'], name: 'api.episode.undo_rewatch', requirements: ['tvdbId' => '\d+'])]
class UndoRewatch extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $episode = new Episode();
        if ($episode->loadWithTvdbId($tvdbId)) {
            (new WatchedEpisode())->resetToSingleWatch($this->user->getID(), $episode->getInfo()['id_episode']);
        }
    }

}

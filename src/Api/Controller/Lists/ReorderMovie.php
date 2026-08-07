<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Core\Routing\Attribute\Route;

/** `after` omitted/empty moves to the front - same relative-to-neighbor reasoning as Lists\ReorderSerie */
#[Route('/lists/{id}/movies/{tvdbId}/reorder', methods: ['POST'], name: 'api.lists.reorder_movie', requirements: ['id' => '\d+', 'tvdbId' => '\d+'])]
class ReorderMovie extends Controller
{

    protected function run(): void
    {
        $id     = (int) $this->getParam('id');
        $tvdbId = (int) $this->getParam('tvdbId');
        $after  = ($_POST['after'] ?? '') !== '' ? (int) $_POST['after'] : null;

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $movie = new Movie();
        if (!$movie->loadWithTvdbId($tvdbId)) {
            $this->error = '404';
            return;
        }
        $idMovie = $movie->getInfo()['id_movie'];

        $afterIdMovie = null;
        if ($after !== null) {
            $afterMovie = new Movie();
            if (!$afterMovie->loadWithTvdbId($after)) {
                $this->error = 'Invalid `after` reference.';
                return;
            }
            $afterIdMovie = $afterMovie->getInfo()['id_movie'];
        }

        (new UserListMovie())->moveAfter($id, $idMovie, $afterIdMovie);
    }

}

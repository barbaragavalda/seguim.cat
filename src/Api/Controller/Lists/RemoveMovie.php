<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Core\Routing\Attribute\Route;

#[Route('/lists/{id}/movies/{tvdbId}', methods: ['DELETE'], name: 'api.lists.remove_movie', requirements: ['id' => '\d+', 'tvdbId' => '\d+'])]
class RemoveMovie extends Controller
{

    protected function run(): void
    {
        $id     = (int) $this->getParam('id');
        $tvdbId = (int) $this->getParam('tvdbId');

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $movie = new Movie();
        if ($movie->loadWithTvdbId($tvdbId)) {
            (new UserListMovie())->remove($id, $movie->getInfo()['id_movie']);
        }
    }

}

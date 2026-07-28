<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

#[Route('/lists/{id}/series/{tvdbId}', methods: ['DELETE'], name: 'api.lists.remove_serie', requirements: ['id' => '\d+', 'tvdbId' => '\d+'])]
class RemoveSerie extends Controller
{

    protected function run(): void
    {
        $id     = (int) $this->getParam('id');
        $tvdbId = (int) $this->getParam('tvdbId');

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $series = new Series();
        if ($series->loadWithTvdbId($tvdbId)) {
            (new UserListSerie())->remove($id, $series->getInfo()['id_serie']);
        }
    }

}

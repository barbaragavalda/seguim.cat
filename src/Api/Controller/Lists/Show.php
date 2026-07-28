<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

#[Route('/lists/{id}', methods: ['GET'], name: 'api.lists.show', requirements: ['id' => '\d+'])]
class Show extends Controller
{

    protected function run(): void
    {
        $id   = (int) $this->getParam('id');
        $page = max(0, (int) ($_GET['page'] ?? 0));

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $result = (new UserListSerie())->listForList($id, $page);
        $this->assign('series', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

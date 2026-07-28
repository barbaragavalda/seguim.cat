<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Core\Routing\Attribute\Route;

#[Route('/lists', methods: ['GET'], name: 'api.lists.index')]
class Index extends Controller
{

    protected function run(): void
    {
        $page = max(0, (int) ($_GET['page'] ?? 0));

        $result = (new UserList())->listForUser($this->user->getID(), $page);
        $this->assign('lists', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

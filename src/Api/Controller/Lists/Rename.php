<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Core\Routing\Attribute\Route;

#[Route('/lists/{id}', methods: ['POST'], name: 'api.lists.rename', requirements: ['id' => '\d+'])]
class Rename extends Controller
{

    protected function run(): void
    {
        $id   = (int) $this->getParam('id');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->error = 'A name is required.';
            return;
        }

        $userList = new UserList();
        if (!$userList->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $userList->rename($this->user->getID(), $id, $name);
        $this->assign('name', $name);
    }

}

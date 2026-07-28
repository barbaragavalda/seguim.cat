<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Core\Routing\Attribute\Route;

#[Route('/lists/{id}', methods: ['DELETE'], name: 'api.lists.delete', requirements: ['id' => '\d+'])]
class Delete extends Controller
{

    protected function run(): void
    {
        $id = (int) $this->getParam('id');

        $userList = new UserList();
        if ($userList->belongsToUser($this->user->getID(), $id)) {
            $userList->delete($this->user->getID(), $id);
        }
    }

}

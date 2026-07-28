<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Core\Routing\Attribute\Route;

#[Route('/lists', methods: ['POST'], name: 'api.lists.create')]
class Create extends Controller
{

    protected function run(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->error = 'A name is required.';
            return;
        }

        $id = (new UserList())->create($this->user->getID(), $name);
        $this->assign('id', $id);
    }

}

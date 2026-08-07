<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Core\Routing\Attribute\Route;

/**
 * `after` omitted/empty moves to the front - see UserList::moveAfter() for
 * why reordering is relative-to-neighbor, not full-order submission (pagination).
 */
#[Route('/lists/{id}/reorder', methods: ['POST'], name: 'api.lists.reorder', requirements: ['id' => '\d+'])]
class Reorder extends Controller
{

    protected function run(): void
    {
        $id      = (int) $this->getParam('id');
        $afterId = ($_POST['after'] ?? '') !== '' ? (int) $_POST['after'] : null;

        $userList = new UserList();
        if (!$userList->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }
        if ($afterId !== null && !$userList->belongsToUser($this->user->getID(), $afterId)) {
            $this->error = 'Invalid `after` reference.';
            return;
        }

        $userList->moveAfter($this->user->getID(), $id, $afterId);
    }

}

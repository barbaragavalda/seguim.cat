<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Core\Routing\Attribute\Route;

/**
 * Moves this list to right after `after` among the user's own lists, or to
 * the very front if `after` is omitted/empty - see UserList::moveAfter()'s
 * own docblock for why this "relative to a visible neighbor" shape was
 * chosen instead of submitting the full order (pagination)
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

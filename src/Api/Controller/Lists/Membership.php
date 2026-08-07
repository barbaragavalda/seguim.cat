<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

/**
 * Deliberately doesn't sync() the series (unlike AddSerie) - by now it was
 * already synced to show its own detail, and an unsynced series can't be
 * in any list anyway.
 */
#[Route('/lists/membership/{tvdbId}', methods: ['GET'], name: 'api.lists.membership', requirements: ['tvdbId' => '\d+'])]
class Membership extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');
        $idUser = $this->user->getID();

        $idSerie = (new Series())->loadWithTvdbId($tvdbId);
        $inListIds = $idSerie === false
            ? array()
            : (new UserListSerie())->listIdsContainingSerie($idUser, (int) $idSerie);

        $lists = array_map(
            static fn (array $list): array => array(
                'id_user_list' => (int) $list['id_user_list'],
                'name'         => $list['name'],
                'in_list'      => in_array((int) $list['id_user_list'], $inListIds, true),
            ),
            (new UserList())->allForUser($idUser),
        );
        $this->assign('lists', $lists);
    }

}

<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

/**
 * Every one of the user's own lists, each flagged with whether $tvdbId is
 * already in it - backs the multi-list "add to a list" picker on the
 * series detail screen. Deliberately doesn't sync() the series (unlike
 * AddSerie) - by the time this screen is reachable the series was already
 * synced to show its own detail, and a series that was never synced can't
 * be in any list anyway, so a plain local lookup is enough.
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

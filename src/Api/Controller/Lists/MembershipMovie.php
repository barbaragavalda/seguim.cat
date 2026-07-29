<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Core\Routing\Attribute\Route;

/**
 * Every one of the user's own lists, each flagged with whether $tvdbId is
 * already in it - backs the multi-list "add to a list" picker on the movie
 * detail screen. Exact mirror of Lists\Membership (series) - see its own
 * docblock for why this doesn't sync() the movie.
 */
#[Route('/lists/membership/movie/{tvdbId}', methods: ['GET'], name: 'api.lists.membership_movie', requirements: ['tvdbId' => '\d+'])]
class MembershipMovie extends Controller
{

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');
        $idUser = $this->user->getID();

        $idMovie   = (new Movie())->loadWithTvdbId($tvdbId);
        $inListIds = $idMovie === false
            ? array()
            : (new UserListMovie())->listIdsContainingMovie($idUser, (int) $idMovie);

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

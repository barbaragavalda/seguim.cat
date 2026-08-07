<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

#[Route('/lists', methods: ['GET'], name: 'api.lists.index')]
class Index extends Controller
{

    // series first (own order), topped up with movies - see
    // UserListMovie::previewForList() for why they're not merged by date
    private const int PREVIEW_LIMIT = 5;

    protected function run(): void
    {
        $page = max(0, (int) ($_GET['page'] ?? 0));

        $result = (new UserList())->listForUser($this->user->getID(), $page);

        $userListSerie = new UserListSerie();
        $userListMovie = new UserListMovie();

        $lists = array();
        foreach ($result['results'] as $list) {
            $idUserList = (int) $list['id_user_list'];

            $preview = array_map(
                static fn(array $row): array => array('type' => 'series', 'tvdb_id' => (int) $row['tvdb_id'], 'image' => $row['image']),
                $userListSerie->previewForList($idUserList, self::PREVIEW_LIMIT),
            );
            if (count($preview) < self::PREVIEW_LIMIT) {
                $preview = array_merge($preview, array_map(
                    static fn(array $row): array => array('type' => 'movie', 'tvdb_id' => (int) $row['tvdb_id'], 'image' => $row['image']),
                    $userListMovie->previewForList($idUserList, self::PREVIEW_LIMIT - count($preview)),
                ));
            }

            $list['series_count'] = $userListSerie->countForList($idUserList);
            $list['movies_count'] = $userListMovie->countForList($idUserList);
            $list['preview']      = $preview;
            $lists[]              = $list;
        }

        $this->assign('lists', $lists);
        $this->assign('hasMore', $result['hasMore']);
    }

}

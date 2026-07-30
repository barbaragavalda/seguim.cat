<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\MovieImportPending;
use Api\Model\SeriesImportPending;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

/**
 * A list's series and movies are fetched together (own independent page/
 * hasMore per kind, since they're paginated separately - own ordering,
 * own PAGE_SIZE) rather than needing two requests, since a list detail
 * screen shows both sections at once anyway.
 */
#[Route('/lists/{id}', methods: ['GET'], name: 'api.lists.show', requirements: ['id' => '\d+'])]
class Show extends Controller
{

    protected function run(): void
    {
        $id        = (int) $this->getParam('id');
        $page      = max(0, (int) ($_GET['page'] ?? 0));
        $moviePage = max(0, (int) ($_GET['movie_page'] ?? 0));

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $series = (new UserListSerie())->listForList($id, $page);
        $this->assign('series', $series['results']);
        $this->assign('hasMore', $series['hasMore']);

        $movies = (new UserListMovie())->listForList($id, $moviePage);
        $this->assign('movies', $movies['results']);
        $this->assign('moviesHasMore', $movies['hasMore']);

        // how many of this list's own members are still waiting on a
        // pending series_import_pending/movie_import_pending row - see
        // those tables' own docblocks in db.sql (Processor::processLists()
        // links a still-unresolved series/movie to its list instead of
        // silently dropping it)
        $this->assign('pendingCount', (new SeriesImportPending())->pendingCountForList($id) + (new MovieImportPending())->pendingCountForList($id));
    }

}

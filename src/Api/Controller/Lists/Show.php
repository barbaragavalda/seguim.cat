<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Episode;
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
        $this->assign('series', $this->withWatchProgress($series['results']));
        $this->assign('hasMore', $series['hasMore']);

        $movies = (new UserListMovie())->listForList($id, $moviePage);
        $this->assign('movies', $movies['results']);
        $this->assign('moviesHasMore', $movies['hasMore']);

        // how many of this list's own members are still waiting on a
        // pending user_serie_pending/user_movie_pending row - see
        // those tables' own docblocks in db.sql (Processor::processLists()
        // links a still-unresolved series/movie to its list instead of
        // silently dropping it)
        $this->assign('pendingCount', (new SeriesImportPending())->pendingCountForList($id) + (new MovieImportPending())->pendingCountForList($id));
    }

    /**
     * attaches watched_episodes/total_episodes to every row - unlike
     * Search\Search's own version of this, every row here already has a
     * real id_serie (straight from the `serie` table, see
     * UserListSerie::listForList()), so there's no tvdb_id lookup step
     */
    private function withWatchProgress(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $idSeries = array_map(static fn(array $r): int => (int) $r['id_serie'], $rows);
        $progress = (new Episode())->watchProgressForSeries($this->user->getID(), $idSeries);

        foreach ($rows as &$row) {
            $idSerie = (int) $row['id_serie'];
            if (isset($progress[$idSerie])) {
                $row['watched_episodes'] = $progress[$idSerie]['watched'];
                $row['total_episodes']   = $progress[$idSerie]['total'];
            }
        }
        unset($row);

        return $rows;
    }

}

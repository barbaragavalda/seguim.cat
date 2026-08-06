<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\SerieFavorite;
use Core\Routing\Attribute\Route;

/**
 * Paginated, most-recently-favorited-first - backs FavoritesDetailScreen's
 * series grid, reached from ProfileScreen's own "Sèries favorites" row.
 */
#[Route('/favorites/series', methods: ['GET'], name: 'api.favorites.series')]
class Series extends Controller
{

    protected function run(): void
    {
        $page = max(0, (int) ($_GET['page'] ?? 0));

        $result = (new SerieFavorite())->listForUser($this->user->getID(), $page);
        $this->assign('series', $this->withWatchProgress($result['results']));
        $this->assign('hasMore', $result['hasMore']);
    }

    /**
     * see Api\Controller\Lists\Show::withWatchProgress()'s own docblock,
     * identical reasoning
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

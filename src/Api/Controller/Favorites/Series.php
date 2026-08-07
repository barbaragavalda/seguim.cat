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
        $this->assign('series', (new Episode())->attachWatchProgress($result['results'], $this->user->getID()));
        $this->assign('hasMore', $result['hasMore']);
    }

}

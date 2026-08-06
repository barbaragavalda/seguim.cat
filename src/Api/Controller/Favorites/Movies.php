<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\MovieFavorite;
use Core\Routing\Attribute\Route;

/**
 * Paginated, most-recently-favorited-first - backs FavoritesDetailScreen's
 * movies grid, reached from ProfileScreen's own "Pel·lícules favorites" row.
 */
#[Route('/favorites/movies', methods: ['GET'], name: 'api.favorites.movies')]
class Movies extends Controller
{

    protected function run(): void
    {
        $page = max(0, (int) ($_GET['page'] ?? 0));

        $result = (new MovieFavorite())->listForUser($this->user->getID(), $page);
        $this->assign('movies', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

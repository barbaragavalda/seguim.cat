<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\MovieFavorite;
use Api\Model\SerieFavorite;
use Core\Routing\Attribute\Route;

/**
 * Count + small poster preview for both favorite kinds in one call - backs
 * the two fixed rows ("Sèries favorites"/"Pel·lícules favorites") pinned
 * above ProfileScreen's own list-preview rows, same visual shape as
 * Api\Controller\Lists\Index's own per-list preview.
 */
#[Route('/favorites/summary', methods: ['GET'], name: 'api.favorites.summary')]
class Summary extends Controller
{

    // mirrors Lists\Index::PREVIEW_LIMIT
    private const int PREVIEW_LIMIT = 5;

    protected function run(): void
    {
        $idUser = $this->user->getID();

        $serieFavorite = new SerieFavorite();
        $movieFavorite = new MovieFavorite();

        $this->assign('series_count', $serieFavorite->countForUser($idUser));
        $this->assign('series_preview', array_map(
            static fn(array $row): array => array('tvdb_id' => (int) $row['tvdb_id'], 'image' => $row['image']),
            $serieFavorite->previewForUser($idUser, self::PREVIEW_LIMIT),
        ));

        $this->assign('movies_count', $movieFavorite->countForUser($idUser));
        $this->assign('movies_preview', array_map(
            static fn(array $row): array => array('tvdb_id' => (int) $row['tvdb_id'], 'image' => $row['image']),
            $movieFavorite->previewForUser($idUser, self::PREVIEW_LIMIT),
        ));
    }

}

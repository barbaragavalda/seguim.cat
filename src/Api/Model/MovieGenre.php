<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * TheTVDB's genre taxonomy has no per-language translation (confirmed
 * empirically - GET /genres and a genre's own name never vary with
 * Accept-Language), so unlike movie_lang there's no *_lang table here -
 * `slug` is stored so the app can localize the label itself (client-side
 * l10n, same as it already does for status), falling back to the raw
 * English `name` for a slug it doesn't have a translation for yet.
 */
class MovieGenre extends Model
{

    public function syncForMovie(int $idMovie, array $genres): void
    {
        $this->deleteForMovie($idMovie);
        foreach ($genres as $genre) {
            $this->insert($idMovie, $genre);
        }
    }

    public function forMovie(int $idMovie): array
    {
        $sql    = '
            SELECT tvdb_genre_id, slug, name
            FROM movie_genre
            WHERE id_movie = :id_movie
            ORDER BY name
        ';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    private function deleteForMovie(int $idMovie): void
    {
        $sql    = 'DELETE FROM movie_genre WHERE id_movie = :id_movie';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function insert(int $idMovie, array $genre): void
    {
        if (empty($genre['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO movie_genre (id_movie, tvdb_genre_id, slug, name)
            VALUES (:id_movie, :tvdb_genre_id, :slug, :name)
        ';
        $params = array(
            'id_movie'      => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_genre_id' => array('value' => $genre['id'], 'type' => PDO::PARAM_INT),
            'slug'          => array('value' => $genre['slug'] ?? null, 'type' => PDO::PARAM_STR),
            'name'          => array('value' => $genre['name'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * a plain join table against Api\Model\Genre - see that class' own
 * docblock for why slug/name live there instead of here
 */
class MovieGenre extends Model
{

    public function syncForMovie(int $idMovie, array $genres): void
    {
        $this->deleteForMovie($idMovie);
        $genreModel = new Genre();
        foreach ($genres as $genre) {
            $genreModel->upsert($genre);
            $this->insert($idMovie, $genre);
        }
    }

    public function forMovie(int $idMovie): array
    {
        $sql    = '
            SELECT g.tvdb_genre_id, g.slug, g.name
            FROM movie_genre mg
            INNER JOIN genre g ON g.tvdb_genre_id = mg.tvdb_genre_id
            WHERE mg.id_movie = :id_movie
            ORDER BY g.name
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
            INSERT INTO movie_genre (id_movie, tvdb_genre_id)
            VALUES (:id_movie, :tvdb_genre_id)
        ';
        $params = array(
            'id_movie'      => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_genre_id' => array('value' => $genre['id'], 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

}

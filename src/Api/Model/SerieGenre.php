<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * a plain join table against Api\Model\Genre - see that class' own
 * docblock for why slug/name live there instead of here (counterpart of
 * Api\Model\MovieGenre)
 */
class SerieGenre extends Model
{

    public function syncForSerie(int $idSerie, array $genres): void
    {
        $this->deleteForSerie($idSerie);
        $genreModel = new Genre();
        foreach ($genres as $genre) {
            $genreModel->upsert($genre);
            $this->insert($idSerie, $genre);
        }
    }

    public function forSerie(int $idSerie): array
    {
        $sql    = '
            SELECT g.tvdb_genre_id, g.slug, g.name
            FROM serie_genre sg
            INNER JOIN genre g ON g.tvdb_genre_id = sg.tvdb_genre_id
            WHERE sg.id_serie = :id_serie
            ORDER BY g.name
        ';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    private function deleteForSerie(int $idSerie): void
    {
        $sql    = 'DELETE FROM serie_genre WHERE id_serie = :id_serie';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function insert(int $idSerie, array $genre): void
    {
        if (empty($genre['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO serie_genre (id_serie, tvdb_genre_id)
            VALUES (:id_serie, :tvdb_genre_id)
        ';
        $params = array(
            'id_serie'      => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'tvdb_genre_id' => array('value' => $genre['id'], 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

}

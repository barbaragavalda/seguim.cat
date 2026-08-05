<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * counterpart of Api\Model\MovieGenre - same reasoning throughout (no
 * per-language translation in TheTVDB's own genre taxonomy, `slug` stored
 * for client-side l10n, full replace each sync cycle)
 */
class SerieGenre extends Model
{

    public function syncForSerie(int $idSerie, array $genres): void
    {
        $this->deleteForSerie($idSerie);
        foreach ($genres as $genre) {
            $this->insert($idSerie, $genre);
        }
    }

    public function forSerie(int $idSerie): array
    {
        $sql    = '
            SELECT tvdb_genre_id, slug, name
            FROM serie_genre
            WHERE id_serie = :id_serie
            ORDER BY name
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
            INSERT INTO serie_genre (id_serie, tvdb_genre_id, slug, name)
            VALUES (:id_serie, :tvdb_genre_id, :slug, :name)
        ';
        $params = array(
            'id_serie'      => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'tvdb_genre_id' => array('value' => $genre['id'], 'type' => PDO::PARAM_INT),
            'slug'          => array('value' => $genre['slug'] ?? null, 'type' => PDO::PARAM_STR),
            'name'          => array('value' => $genre['name'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

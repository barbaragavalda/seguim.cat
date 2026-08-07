<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * TheTVDB's genre taxonomy - shared between movies and series (same ids),
 * so movie_genre/serie_genre are plain join tables against this one.
 * `slug` lets the app localize the label client-side, falling back to the
 * raw English `name` when no translation exists yet.
 */
class Genre extends Model
{

    /** Upserted, not inserted - the same genre id is synced once per movie/series that has it. */
    public function upsert(array $genre): void
    {
        if (empty($genre['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO genre (tvdb_genre_id, slug, name)
            VALUES (:tvdb_genre_id, :slug, :name)
            ON DUPLICATE KEY UPDATE slug = :slug_upd, name = :name_upd
        ';
        $params = array(
            'tvdb_genre_id' => array('value' => $genre['id'], 'type' => PDO::PARAM_INT),
            'slug'          => array('value' => $genre['slug'] ?? null, 'type' => PDO::PARAM_STR),
            'name'          => array('value' => $genre['name'] ?? null, 'type' => PDO::PARAM_STR),
            'slug_upd'      => array('value' => $genre['slug'] ?? null, 'type' => PDO::PARAM_STR),
            'name_upd'      => array('value' => $genre['name'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

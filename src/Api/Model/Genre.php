<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * TheTVDB's own genre taxonomy - shared between movies and series (same
 * ids, confirmed empirically), so movie_genre/serie_genre are plain join
 * tables against this one rather than each keeping their own copy of
 * slug/name. No per-language translation (confirmed empirically - GET
 * /genres and a genre's own name never vary with Accept-Language), so
 * `slug` is stored for the app to localize the label itself client-side,
 * falling back to the raw English `name` for a slug it doesn't have a
 * translation for yet.
 */
class Genre extends Model
{

    /**
     * upserted rather than inserted since the same genre id is synced
     * repeatedly (once per movie/series that has it) - a plain INSERT
     * would fail every time after the first
     */
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

<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * TheTVDB's own content-rating catalog - age ratings (e.g. "PG-13") are
 * country-specific, not language-specific (TheTVDB ties each one to a
 * `country`, not a language), but still a global, stable, shared catalog
 * keyed by tvdb_rating_id - same reasoning as Api\Model\Genre, just per
 * (country, rating) pair instead of per genre. movie_content_rating is a
 * plain join table against this one.
 */
class ContentRating extends Model
{

    /**
     * upserted rather than inserted since the same rating id is synced
     * repeatedly (once per movie that has it) - a plain INSERT would fail
     * every time after the first
     */
    public function upsert(array $rating): void
    {
        if (empty($rating['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO content_rating (tvdb_rating_id, country, rating, description)
            VALUES (:tvdb_rating_id, :country, :rating, :description)
            ON DUPLICATE KEY UPDATE
                country = :country_upd, rating = :rating_upd, description = :description_upd
        ';
        $params = array(
            'tvdb_rating_id'  => array('value' => $rating['id'], 'type' => PDO::PARAM_INT),
            'country'         => array('value' => $rating['country'] ?? null, 'type' => PDO::PARAM_STR),
            'rating'          => array('value' => $rating['name'] ?? null, 'type' => PDO::PARAM_STR),
            'description'     => array('value' => $rating['description'] ?? null, 'type' => PDO::PARAM_STR),
            'country_upd'     => array('value' => $rating['country'] ?? null, 'type' => PDO::PARAM_STR),
            'rating_upd'      => array('value' => $rating['name'] ?? null, 'type' => PDO::PARAM_STR),
            'description_upd' => array('value' => $rating['description'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

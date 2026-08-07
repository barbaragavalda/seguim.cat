<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * A cast member's identity (name/photo) - shared across every movie/series
 * they appear in (same tvdb_people_id), unlike their character name/sort
 * order which are specific to one production and live on movie_cast.
 */
class Person extends Model
{

    /** Upserted, not inserted - the same person is synced once per movie/series they're cast in. */
    public function upsert(array $character): void
    {
        if (empty($character['peopleId'])) {
            return;
        }
        $sql    = '
            INSERT INTO person (tvdb_people_id, name, image)
            VALUES (:tvdb_people_id, :name, :image)
            ON DUPLICATE KEY UPDATE name = :name_upd, image = :image_upd
        ';
        $params = array(
            'tvdb_people_id' => array('value' => $character['peopleId'], 'type' => PDO::PARAM_INT),
            'name'           => array('value' => $character['personName'] ?? null, 'type' => PDO::PARAM_STR),
            'image'          => array('value' => $character['personImgURL'] ?? $character['image'] ?? null, 'type' => PDO::PARAM_STR),
            'name_upd'       => array('value' => $character['personName'] ?? null, 'type' => PDO::PARAM_STR),
            'image_upd'      => array('value' => $character['personImgURL'] ?? $character['image'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

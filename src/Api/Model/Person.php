<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * a cast member's identity (name/photo) - shared across every movie/series
 * they appear in (same tvdb_people_id, confirmed empirically), unlike their
 * character name/sort order which are specific to one production.
 * movie_cast is a plain join table against this one for the person's own
 * identity, keeping tvdb_character_id/character_name/sort_order (the
 * per-casting data) on its own row.
 */
class Person extends Model
{

    /**
     * upserted rather than inserted since the same person is synced
     * repeatedly (once per movie/series they're cast in) - a plain INSERT
     * would fail every time after the first
     */
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

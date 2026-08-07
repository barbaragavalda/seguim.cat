<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * TheTVDB's `characters` array mixes actors with crew (director/writer/
 * producer, distinguished by peopleType) - only peopleType === 'Actor' rows
 * are kept. A join table against Api\Model\Person for identity (name/
 * image); keeps only per-casting data (character name, sort order) here.
 */
class MovieCast extends Model
{

    private const int DEFAULT_LIMIT = 10;

    public function syncForMovie(int $idMovie, array $characters): void
    {
        $this->deleteForMovie($idMovie);
        $personModel = new Person();
        foreach ($characters as $character) {
            if (($character['peopleType'] ?? null) !== 'Actor') {
                continue;
            }
            $personModel->upsert($character);
            $this->insert($idMovie, $character);
        }
    }

    public function forMovie(int $idMovie, int $limit = self::DEFAULT_LIMIT): array
    {
        // LEFT, not INNER - a character with no linked person (allowed by
        // the schema) should still show, just without a name/photo
        $sql    = '
            SELECT mc.tvdb_character_id, mc.tvdb_people_id, p.name AS person_name, mc.character_name, p.image
            FROM movie_cast mc
            LEFT JOIN person p ON p.tvdb_people_id = mc.tvdb_people_id
            WHERE mc.id_movie = :id_movie
            ORDER BY mc.sort_order
            LIMIT :limit
        ';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'limit'    => array('value' => $limit, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    private function deleteForMovie(int $idMovie): void
    {
        $sql    = 'DELETE FROM movie_cast WHERE id_movie = :id_movie';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function insert(int $idMovie, array $character): void
    {
        if (empty($character['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO movie_cast (id_movie, tvdb_character_id, tvdb_people_id, character_name, sort_order)
            VALUES (:id_movie, :tvdb_character_id, :tvdb_people_id, :character_name, :sort_order)
        ';
        $params = array(
            'id_movie'          => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_character_id' => array('value' => $character['id'], 'type' => PDO::PARAM_INT),
            'tvdb_people_id'    => array('value' => $character['peopleId'] ?? null, 'type' => PDO::PARAM_INT),
            'character_name'    => array('value' => $character['name'] ?? null, 'type' => PDO::PARAM_STR),
            'sort_order'        => array('value' => $character['sort'] ?? null, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

}

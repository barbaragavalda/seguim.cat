<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * top-billed cast for a movie's detail screen. TheTVDB's `characters` array
 * mixes actors with crew (director/writer/producer, distinguished by
 * peopleType) - only peopleType === 'Actor' rows are kept. Person/character
 * names have no per-language translation in practice (nameTranslations is
 * null on every character TheTVDB actually returns), so - like MovieGenre -
 * there's no *_lang table here.
 */
class MovieCast extends Model
{

    private const int DEFAULT_LIMIT = 10;

    public function syncForMovie(int $idMovie, array $characters): void
    {
        $this->deleteForMovie($idMovie);
        foreach ($characters as $character) {
            if (($character['peopleType'] ?? null) !== 'Actor') {
                continue;
            }
            $this->insert($idMovie, $character);
        }
    }

    public function forMovie(int $idMovie, int $limit = self::DEFAULT_LIMIT): array
    {
        $sql    = '
            SELECT tvdb_character_id, tvdb_people_id, person_name, character_name, image
            FROM movie_cast
            WHERE id_movie = :id_movie
            ORDER BY sort_order
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
            INSERT INTO movie_cast (id_movie, tvdb_character_id, tvdb_people_id, person_name, character_name, image, sort_order)
            VALUES (:id_movie, :tvdb_character_id, :tvdb_people_id, :person_name, :character_name, :image, :sort_order)
        ';
        $params = array(
            'id_movie'          => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_character_id' => array('value' => $character['id'], 'type' => PDO::PARAM_INT),
            'tvdb_people_id'    => array('value' => $character['peopleId'] ?? null, 'type' => PDO::PARAM_INT),
            'person_name'       => array('value' => $character['personName'] ?? null, 'type' => PDO::PARAM_STR),
            'character_name'    => array('value' => $character['name'] ?? null, 'type' => PDO::PARAM_STR),
            'image'             => array('value' => $character['personImgURL'] ?? $character['image'] ?? null, 'type' => PDO::PARAM_STR),
            'sort_order'        => array('value' => $character['sort'] ?? null, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

}

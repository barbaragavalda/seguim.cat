<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * age ratings (e.g. "PG-13") are country-specific, not language-specific -
 * TheTVDB ties each one to a `country`, not a language, so there's no
 * *_lang table here either (the same reasoning as MovieGenre/MovieCast:
 * nothing here has an actual per-language translation to sync).
 */
class MovieContentRating extends Model
{

    public function syncForMovie(int $idMovie, array $contentRatings): void
    {
        $this->deleteForMovie($idMovie);
        foreach ($contentRatings as $rating) {
            $this->insert($idMovie, $rating);
        }
    }

    /**
     * prefers a rating matching $country (see Api\Model\TheTvdb\Languages::
     * tvdbCountryForCulture()), falls back to whatever's available so a
     * movie without a rating for the user's own country still shows one
     */
    public function bestForCountry(int $idMovie, ?string $country): ?array
    {
        $sql    = '
            SELECT country, rating, description
            FROM movie_content_rating
            WHERE id_movie = :id_movie
            ORDER BY (country = :country) DESC, id_movie_content_rating
            LIMIT 1
        ';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'country'  => array('value' => $country ?? '', 'type' => PDO::PARAM_STR),
        );
        $rows = $this->mysql->query($sql, $params);
        return $rows[0] ?? null;
    }

    private function deleteForMovie(int $idMovie): void
    {
        $sql    = 'DELETE FROM movie_content_rating WHERE id_movie = :id_movie';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function insert(int $idMovie, array $rating): void
    {
        if (empty($rating['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO movie_content_rating (id_movie, tvdb_rating_id, country, rating, description)
            VALUES (:id_movie, :tvdb_rating_id, :country, :rating, :description)
        ';
        $params = array(
            'id_movie'       => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_rating_id' => array('value' => $rating['id'], 'type' => PDO::PARAM_INT),
            'country'        => array('value' => $rating['country'] ?? null, 'type' => PDO::PARAM_STR),
            'rating'         => array('value' => $rating['name'] ?? null, 'type' => PDO::PARAM_STR),
            'description'    => array('value' => $rating['description'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * a plain join table against Api\Model\ContentRating - see that class' own
 * docblock for why country/rating/description live there instead of here
 */
class MovieContentRating extends Model
{

    public function syncForMovie(int $idMovie, array $contentRatings): void
    {
        $this->deleteForMovie($idMovie);
        $ratingModel = new ContentRating();
        foreach ($contentRatings as $rating) {
            $ratingModel->upsert($rating);
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
            SELECT cr.country, cr.rating, cr.description
            FROM movie_content_rating mcr
            INNER JOIN content_rating cr ON cr.tvdb_rating_id = mcr.tvdb_rating_id
            WHERE mcr.id_movie = :id_movie
            ORDER BY (cr.country = :country) DESC, mcr.id_movie_content_rating
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
            INSERT INTO movie_content_rating (id_movie, tvdb_rating_id)
            VALUES (:id_movie, :tvdb_rating_id)
        ';
        $params = array(
            'id_movie'       => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_rating_id' => array('value' => $rating['id'], 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

}

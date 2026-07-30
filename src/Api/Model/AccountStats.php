<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * profile screen's own summary counters. Watched counts are DISTINCT
 * episodes/movies, not raw row counts - user_episode_watched/
 * user_movie_watched have one row per watch *event* (a rewatch adds
 * another row), so a plain COUNT(*) would inflate these with rewatches
 * rather than reflecting how many distinct episodes/movies were ever seen.
 * Added counts are plain row counts - a watchlist row is unique per
 * (id_user, id_serie)/(id_user, id_movie) already, so there's nothing to
 * dedupe there.
 */
class AccountStats extends Model
{

    public function forUser(int $idUser): array
    {
        return array(
            'episodes_watched' => $this->count(
                'SELECT COUNT(DISTINCT id_episode) AS cnt FROM user_episode_watched WHERE id_user = :id_user',
                $idUser,
            ),
            'series_added' => $this->count(
                'SELECT COUNT(*) AS cnt FROM user_serie_watchlist WHERE id_user = :id_user',
                $idUser,
            ),
            'movies_watched' => $this->count(
                'SELECT COUNT(DISTINCT id_movie) AS cnt FROM user_movie_watched WHERE id_user = :id_user',
                $idUser,
            ),
            'movies_added' => $this->count(
                'SELECT COUNT(*) AS cnt FROM user_movie_watchlist WHERE id_user = :id_user',
                $idUser,
            ),
        );
    }

    private function count(string $sql, int $idUser): int
    {
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $rows = $this->mysql->query($sql, $params);
        return (int) ($rows[0]['cnt'] ?? 0);
    }

}

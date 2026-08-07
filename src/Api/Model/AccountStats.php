<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * Watched counts use DISTINCT since watch tables have one row per watch
 * event (a rewatch adds a row); added counts are plain row counts since
 * watchlist rows are already unique per user.
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

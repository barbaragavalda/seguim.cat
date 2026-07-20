<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class WatchedEpisode extends Model
{

    public function markWatched(int $idUser, int $idEpisode): void
    {
        $sql    = '
            INSERT IGNORE INTO user_episode_watched (id_user, id_episode)
            VALUES (:id_user, :id_episode)
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function markUnwatched(int $idUser, int $idEpisode): void
    {
        $sql    = '
            DELETE FROM user_episode_watched
            WHERE id_user = :id_user AND id_episode = :id_episode
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * @return int[] ids (episode.id_episode) of the user's watched episodes within $idSeries
     */
    public function watchedEpisodeIds(int $idUser, int $idSeries): array
    {
        $sql    = '
            SELECT w.id_episode
            FROM user_episode_watched w
            INNER JOIN episode e ON e.id_episode = w.id_episode
            WHERE w.id_user = :id_user AND e.id_series = :id_series
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_series' => array('value' => $idSeries, 'type' => PDO::PARAM_INT),
        );
        return array_column($this->mysql->query($sql, $params), 'id_episode');
    }

}

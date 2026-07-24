<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class WatchedEpisode extends Model
{

    /**
     * $watchedAt preserves TV Time's own original watch date when called
     * from the importer (Api\Model\TvTimeImport\Processor) - defaults to
     * "now" for the regular Episode/Watch controller flow. Without this,
     * every imported episode would tie for the import's own timestamp,
     * making Watchlist::listWatching()'s "most recently watched" ordering
     * meaningless for imported data
     */
    public function markWatched(int $idUser, int $idEpisode, ?string $watchedAt = null): void
    {
        $sql    = '
            INSERT IGNORE INTO user_episode_watched (id_user, id_episode, watched_at)
            VALUES (:id_user, :id_episode, :watched_at)
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
            'watched_at' => array('value' => $watchedAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
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

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_episode_watched
            WHERE id_user = :id_user
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * @return int[] ids (episode.id_episode) of the user's watched episodes within $idSerie
     */
    public function watchedEpisodeIds(int $idUser, int $idSerie): array
    {
        $sql    = '
            SELECT w.id_episode
            FROM user_episode_watched w
            INNER JOIN episode e ON e.id_episode = w.id_episode
            WHERE w.id_user = :id_user AND e.id_serie = :id_serie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return array_column($this->mysql->query($sql, $params), 'id_episode');
    }

}

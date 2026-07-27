<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class WatchedEpisode extends Model
{

    /**
     * idempotent - a no-op if $idEpisode is already watched at all (one or
     * more times), unlike markRewatched() below. $watchedAt preserves TV
     * Time's own original watch date when called from the importer
     * (Api\Model\TvTimeImport\Processor) - defaults to "now" for the
     * regular Episode/Watch controller flow. Without this, every imported
     * episode would tie for the import's own timestamp, making Watchlist::
     * listWatching()'s "most recently watched" ordering meaningless for
     * imported data
     */
    public function markWatched(int $idUser, int $idEpisode, ?string $watchedAt = null): void
    {
        if ($this->isWatched($idUser, $idEpisode)) {
            return;
        }
        $this->insertWatch($idUser, $idEpisode, $watchedAt);
    }

    /**
     * always inserts a new watch event, even if $idEpisode is already
     * watched - this is the point: user_episode_watched is one row per
     * watch event, not per episode, so a rewatch just adds another one
     * rather than being silently absorbed like markWatched() would
     */
    public function markRewatched(int $idUser, int $idEpisode, ?string $watchedAt = null): void
    {
        $this->insertWatch($idUser, $idEpisode, $watchedAt);
    }

    /**
     * the inverse of markRewatched() - collapses every watch event for
     * $idEpisode back down to just the earliest one, undoing any
     * rewatches without fully unwatching it like markUnwatched() does. A
     * no-op if $idEpisode isn't watched at all, or already watched exactly
     * once
     */
    public function resetToSingleWatch(int $idUser, int $idEpisode): void
    {
        $sql    = '
            SELECT MIN(id_user_episode_watched) AS id_user_episode_watched
            FROM user_episode_watched
            WHERE id_user = :id_user AND id_episode = :id_episode
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        if (count($rows) === 0 || $rows[0]['id_user_episode_watched'] === null) {
            return;
        }

        $sql2    = '
            DELETE FROM user_episode_watched
            WHERE id_user = :id_user AND id_episode = :id_episode
              AND id_user_episode_watched != :keep_id
        ';
        $params2 = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
            'keep_id'    => array(
                'value' => (int) $rows[0]['id_user_episode_watched'],
                'type'  => PDO::PARAM_INT,
            ),
        );
        $this->mysql->query($sql2, $params2);
    }

    private function insertWatch(int $idUser, int $idEpisode, ?string $watchedAt): void
    {
        $sql    = '
            INSERT INTO user_episode_watched (id_user, id_episode, watched_at)
            VALUES (:id_user, :id_episode, :watched_at)
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
            'watched_at' => array('value' => $watchedAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    private function isWatched(int $idUser, int $idEpisode): bool
    {
        $sql    = '
            SELECT 1
            FROM user_episode_watched
            WHERE id_user = :id_user AND id_episode = :id_episode
            LIMIT 1
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /**
     * a full reset - every watch event for $idEpisode is removed, not just
     * the most recent rewatch. Undoing a single rewatch (rather than the
     * whole history) isn't something this app supports
     */
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
     * @return int[] distinct ids (episode.id_episode) of the user's watched episodes within $idSerie -
     *               DISTINCT because a rewatched episode now has more than one row
     */
    public function watchedEpisodeIds(int $idUser, int $idSerie): array
    {
        $sql    = '
            SELECT DISTINCT w.id_episode
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

    /**
     * how many times each of $idSerie's episodes has been watched, keyed
     * by episode.id_episode - episodes with zero watches simply don't
     * appear (same convention as watchedEpisodeIds())
     *
     * @return array<int, int>
     */
    public function watchCounts(int $idUser, int $idSerie): array
    {
        $sql    = '
            SELECT w.id_episode, COUNT(*) AS watch_count
            FROM user_episode_watched w
            INNER JOIN episode e ON e.id_episode = w.id_episode
            WHERE w.id_user = :id_user AND e.id_serie = :id_serie
            GROUP BY w.id_episode
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        return array_map('intval', array_column($rows, 'watch_count', 'id_episode'));
    }

}

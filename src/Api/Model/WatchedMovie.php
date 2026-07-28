<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class WatchedMovie extends Model
{

    /**
     * idempotent - a no-op if $idMovie is already watched at all (one or
     * more times), unlike markRewatched() below. Same shape as
     * WatchedEpisode::markWatched(), collapsed to a single entity since a
     * movie has no episodes of its own
     */
    public function markWatched(int $idUser, int $idMovie, ?string $watchedAt = null): void
    {
        if ($this->watchCount($idUser, $idMovie) > 0) {
            return;
        }
        $this->insertWatch($idUser, $idMovie, $watchedAt);
    }

    /**
     * always inserts a new watch event, even if $idMovie is already watched
     * - user_movie_watched is one row per watch event, not per movie, so a
     * rewatch just adds another one rather than being silently absorbed
     * like markWatched() would
     */
    public function markRewatched(int $idUser, int $idMovie, ?string $watchedAt = null): void
    {
        $this->insertWatch($idUser, $idMovie, $watchedAt);
    }

    /**
     * the inverse of markRewatched() - collapses every watch event for
     * $idMovie back down to just the earliest one, undoing any rewatches
     * without fully unwatching it like markUnwatched() does
     */
    public function resetToSingleWatch(int $idUser, int $idMovie): void
    {
        $sql    = '
            SELECT MIN(id_user_movie_watched) AS id_user_movie_watched
            FROM user_movie_watched
            WHERE id_user = :id_user AND id_movie = :id_movie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        if (count($rows) === 0 || $rows[0]['id_user_movie_watched'] === null) {
            return;
        }

        $sql2    = '
            DELETE FROM user_movie_watched
            WHERE id_user = :id_user AND id_movie = :id_movie
              AND id_user_movie_watched != :keep_id
        ';
        $params2 = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'keep_id'  => array(
                'value' => (int) $rows[0]['id_user_movie_watched'],
                'type'  => PDO::PARAM_INT,
            ),
        );
        $this->mysql->query($sql2, $params2);
    }

    /**
     * a full reset - every watch event for $idMovie is removed, not just
     * the most recent rewatch
     */
    public function markUnwatched(int $idUser, int $idMovie): void
    {
        $sql    = '
            DELETE FROM user_movie_watched
            WHERE id_user = :id_user AND id_movie = :id_movie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_movie_watched
            WHERE id_user = :id_user
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * how many times $idMovie has been watched by $idUser - 0 means never
     * watched, unlike WatchedEpisode::watchCounts() this is a single count
     * for a single entity, not a per-episode map
     */
    public function watchCount(int $idUser, int $idMovie): int
    {
        $sql    = '
            SELECT COUNT(*) AS watch_count
            FROM user_movie_watched
            WHERE id_user = :id_user AND id_movie = :id_movie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        return (int) ($rows[0]['watch_count'] ?? 0);
    }

    private function insertWatch(int $idUser, int $idMovie, ?string $watchedAt): void
    {
        $sql    = '
            INSERT INTO user_movie_watched (id_user, id_movie, watched_at)
            VALUES (:id_user, :id_movie, :watched_at)
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie'   => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'watched_at' => array('value' => $watchedAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

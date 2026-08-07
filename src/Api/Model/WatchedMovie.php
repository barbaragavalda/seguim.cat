<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class WatchedMovie extends Model
{

    /**
     * Idempotent - no-op if already watched, unlike markRewatched() below. Never tags the
     * row with an import id, same reasoning as WatchedEpisode::markWatched() - keeps
     * id_user_import IS NOT NULL meaning exactly a syncRewatchFromImport() row, on both
     * tables consistently.
     */
    public function markWatched(int $idUser, int $idMovie, ?string $watchedAt = null): void
    {
        if ($this->watchCount($idUser, $idMovie) > 0) {
            return;
        }
        $this->insertWatch($idUser, $idMovie, $watchedAt);
    }

    /** Always inserts a new watch event, even if already watched - one row per watch event, not per movie. The importer uses syncRewatchFromImport() below instead */
    public function markRewatched(int $idUser, int $idMovie, ?string $watchedAt = null): void
    {
        $this->insertWatch($idUser, $idMovie, $watchedAt);
    }

    /**
     * Unlike an episode rewatch (a bare count, see WatchedEpisode::syncRewatchesFromImport()),
     * each movie rewatch has its own distinct timestamp, which is itself a natural dedup
     * key: if an earlier import already recorded one at this exact moment, re-processing
     * the same export is a no-op instead of inserting a duplicate.
     *
     * @return bool true if a new row was actually inserted
     */
    public function syncRewatchFromImport(int $idUser, int $idMovie, string $watchedAt, int $idTvtimeImport): bool
    {
        if ($this->hasImportedRewatchAt($idUser, $idMovie, $watchedAt)) {
            return false;
        }
        $this->insertWatch($idUser, $idMovie, $watchedAt, $idTvtimeImport);
        return true;
    }

    private function hasImportedRewatchAt(int $idUser, int $idMovie, string $watchedAt): bool
    {
        $sql    = '
            SELECT 1
            FROM user_movie_watched
            WHERE id_user = :id_user AND id_movie = :id_movie
              AND watched_at = :watched_at AND id_user_import IS NOT NULL
            LIMIT 1
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie'   => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'watched_at' => array('value' => $watchedAt, 'type' => PDO::PARAM_STR),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /** Inverse of markRewatched() - collapses every watch event back to the earliest one, without fully unwatching like markUnwatched() does */
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

    /** Full reset - every watch event is removed, not just the most recent rewatch */
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

    /** How many times $idMovie has been watched - unlike WatchedEpisode::watchCounts() this is a single count, not a per-episode map */
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

    private function insertWatch(int $idUser, int $idMovie, ?string $watchedAt, ?int $idTvtimeImport = null): void
    {
        $sql    = '
            INSERT INTO user_movie_watched (id_user, id_movie, watched_at, id_user_import)
            VALUES (:id_user, :id_movie, :watched_at, :id_user_import)
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie'         => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'watched_at'       => array('value' => $watchedAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
            'id_user_import' => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

}

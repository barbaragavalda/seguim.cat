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
     * imported data. Deliberately never tags the row with an import id
     * (unlike syncRewatchesFromImport() below) - this method is already
     * idempotent on its own (isWatched() covers any row regardless of
     * origin), and tagging it would double as a false "already-imported
     * rewatch" for syncRewatchesFromImport()'s own count - confirmed
     * empirically as a real bug (a cpt=2 rewatch only inserted 1 row
     * because the base watch's own tagged row was miscounted as one of
     * the two)
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
     * rather than being silently absorbed like markWatched() would. Used
     * directly by the app's own rewatch controller (one real, freshly-
     * happening event); the importer uses syncRewatchesFromImport() below
     * instead, since TV Time's own export data is a bare count, not
     * discrete events, and needs its own dedup logic to stay safe across
     * more than one import job
     */
    public function markRewatched(int $idUser, int $idEpisode, ?string $watchedAt = null): void
    {
        $this->insertWatch($idUser, $idEpisode, $watchedAt);
    }

    /**
     * TV Time's own export gives a bare rewatch *count* per episode (see
     * Api\Model\TvTimeImport\Parser's own docblock) - never discrete,
     * individually-timestamped events the way a movie rewatch is - so
     * there's no natural key to dedupe individual rewatch rows against
     * across two separate import jobs (e.g. the user re-uploads the same
     * or a newer export). Instead, this counts how many rewatch rows an
     * *earlier* import already recorded for this episode (tagged via
     * $idTvtimeImport on insert) and only inserts the shortfall - so
     * re-importing an unchanged export adds nothing, and a newer export
     * with a higher count only adds the difference. A rewatch logged by
     * the user directly in the app (markRewatched() above, untagged)
     * never counts toward this and is never touched by it.
     *
     * @return int how many new rows were actually inserted
     */
    public function syncRewatchesFromImport(int $idUser, int $idEpisode, int $cpt, string $watchedAt, int $idTvtimeImport): int
    {
        $toInsert = max(0, $cpt - $this->importedRewatchCount($idUser, $idEpisode));
        for ($i = 0; $i < $toInsert; $i++) {
            $this->insertWatch($idUser, $idEpisode, $watchedAt, $idTvtimeImport);
        }
        return $toInsert;
    }

    private function importedRewatchCount(int $idUser, int $idEpisode): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM user_episode_watched
            WHERE id_user = :id_user AND id_episode = :id_episode AND id_tvtime_import IS NOT NULL
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode' => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
        );
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
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

    private function insertWatch(int $idUser, int $idEpisode, ?string $watchedAt, ?int $idTvtimeImport = null): void
    {
        $sql    = '
            INSERT INTO user_episode_watched (id_user, id_episode, watched_at, id_tvtime_import)
            VALUES (:id_user, :id_episode, :watched_at, :id_tvtime_import)
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_episode'       => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
            'watched_at'       => array('value' => $watchedAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
            'id_tvtime_import' => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
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
     * called by Episode::removeStale() when TheTVDB no longer returns
     * episodes that were previously mirrored locally (removed, merged, or
     * renumbered upstream) - every user's watch history for them is wiped
     * along with the episode rows themselves, across every user at once,
     * not just one
     *
     * @param int[] $idEpisodes
     */
    public function removeForEpisodes(array $idEpisodes): void
    {
        if (empty($idEpisodes)) {
            return;
        }

        $params       = array();
        $placeholders = array();
        foreach (array_values($idEpisodes) as $index => $idEpisode) {
            $key            = 'id_episode_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key]   = array('value' => $idEpisode, 'type' => PDO::PARAM_INT);
        }

        $sql = '
            DELETE FROM user_episode_watched
            WHERE id_episode IN (' . implode(',', $placeholders) . ')
        ';
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

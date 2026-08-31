<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * Job tracking for the TV Time GDPR-export importer, processed in resumable
 * time-boxed batches - a full sync can outlast Apache's reverse-proxy
 * timeout (confirmed on a real ~970-show export).
 */
class TvTimeImport extends Model
{

    public function create(int $idUser, string $zipPath): int
    {
        $sql    = '
            INSERT INTO user_import (id_user, zip_path)
            VALUES (:id_user, :zip_path)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'zip_path' => array('value' => $zipPath, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);

        return (int) $this->mysql->lastInsertId();
    }

    /**
     * Called on account deletion and before starting a fresh import, to
     * avoid orphaned job files piling up on disk. Only touches user_import
     * and its files - deliberately never user_movie_pending/
     * user_serie_pending (must outlive the job that created them, or a
     * re-import would re-ask about already-resolved titles) nor the user's
     * actual watch history tables, which must survive a re-import.
     */
    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            SELECT id_user_import, zip_path
            FROM user_import
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $jobs   = $this->mysql->query($sql, $params);

        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            @unlink($job['zip_path']);
            $extractDir = dirname($job['zip_path']) . '/' . $job['id_user_import'];
            if (is_dir($extractDir)) {
                foreach (glob($extractDir . '/*') ?: array() as $file) {
                    @unlink($file);
                }
                @rmdir($extractDir);
            }
        }

        $sql = '
            DELETE FROM user_import
            WHERE id_user = :id_user
        ';
        $this->mysql->query($sql, $params);
    }

    public function findForUser(int $id, int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM user_import
            WHERE id_user_import = :id AND id_user = :id_user
            LIMIT 1
        ';
        $params = array(
            'id'      => array('value' => $id, 'type' => PDO::PARAM_INT),
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * Lets the app recover an in-progress job after a restart, since its
     * in-memory state (TvTimeImportController) doesn't survive the process
     * being killed and relaunched.
     */
    public function findLatestInProgressForUser(int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM user_import
            WHERE id_user = :id_user AND status IN ("pending", "processing")
            ORDER BY created DESC
            LIMIT 1
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $rows   = $this->mysql->query($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * Prevents concurrent requests (multi-tab/device resume, or a poll
     * racing the cron backstop) from double-processing the same job -
     * recordBatch()'s summary counts are additive, not deduped like
     * processed_show_ids, so overlapping calls silently double-count
     * (confirmed live: shows_synced exceeded shows_total). MySQL
     * auto-releases the lock when this connection closes, so a
     * crashed/killed request can't leave it stuck.
     */
    public function acquireProcessingLock(int $id): bool
    {
        $sql    = 'SELECT GET_LOCK(:name, 0) AS acquired';
        $params = array('name' => array('value' => $this->lockName($id), 'type' => PDO::PARAM_STR));
        $rows   = $this->mysql->query($sql, $params);

        return (int) ($rows[0]['acquired'] ?? 0) === 1;
    }

    public function releaseProcessingLock(int $id): void
    {
        $sql    = 'SELECT RELEASE_LOCK(:name)';
        $params = array('name' => array('value' => $this->lockName($id), 'type' => PDO::PARAM_STR));
        $this->mysql->query($sql, $params);
    }

    private function lockName(int $id): string
    {
        return 'user_import_' . $id;
    }

    /**
     * The oldest not-yet-finished job (a job stays 'processing' across
     * every batch until nothing's left), so a repeated cron tick resumes it
     * rather than starting a second one before the first is done.
     */
    public function findNextToProcess(): ?array
    {
        $sql  = '
            SELECT *
            FROM user_import
            WHERE status IN ("pending", "processing")
            ORDER BY created ASC
            LIMIT 1
        ';
        $rows = $this->mysql->query($sql);

        return $rows[0] ?? null;
    }

    public function markProcessing(int $id): void
    {
        $this->updateStatus($id, 'processing');
    }

    public function markDone(int $id): void
    {
        $this->updateStatus($id, 'done');
    }

    /**
     * @return array<int>
     */
    public function getProcessedShowIds(array $job): array
    {
        return !empty($job['processed_show_ids']) ? json_decode($job['processed_show_ids'], true) : array();
    }

    /**
     * @return array<string>
     */
    public function getProcessedListKeys(array $job): array
    {
        return !empty($job['processed_list_keys']) ? json_decode($job['processed_list_keys'], true) : array();
    }

    /**
     * @return array<string>
     */
    public function getProcessedMovieKeys(array $job): array
    {
        return !empty($job['processed_movie_keys']) ? json_decode($job['processed_movie_keys'], true) : array();
    }

    /**
     * Merges this batch's results into what's already recorded; safe as
     * read-then-write since only one batch of one job runs at a time.
     *
     * @param array<int>    $newDoneShowIds
     * @param array<string> $newDoneListKeys
     * @param array<string> $newDoneMovieKeys
     * @param array{
     *     shows_synced: int, shows_failed: array<int>, shows_pending: int, episodes_watched: int, episodes_rewatched: int,
     *     lists_created: int, list_series_added: int, list_movies_added: int, list_series_pending: int, list_movies_pending: int,
     *     movies_synced: int, movies_unmatched: array<string>,
     *     movies_pending: int, movies_watched: int, movies_rewatched: int,
     *     shows_total: int, movies_total: int
     * } $summaryDelta shows_total/movies_total are the export's fixed
     *   totals (same every batch) and are set directly, not accumulated
     *   like the rest.
     */
    public function recordBatch(int $id, array $newDoneShowIds, array $newDoneListKeys, array $newDoneMovieKeys, array $summaryDelta): void
    {
        $sql    = '
            SELECT processed_show_ids, processed_list_keys, processed_movie_keys, summary
            FROM user_import
            WHERE id_user_import = :id
        ';
        $params = array('id' => array('value' => $id, 'type' => PDO::PARAM_INT));
        $job    = $this->mysql->query($sql, $params)[0] ?? array();

        $mergedShowIds   = array_values(array_unique(array_merge($this->getProcessedShowIds($job), $newDoneShowIds)));
        $mergedListKeys  = array_values(array_unique(array_merge($this->getProcessedListKeys($job), $newDoneListKeys)));
        $mergedMovieKeys = array_values(array_unique(array_merge($this->getProcessedMovieKeys($job), $newDoneMovieKeys)));

        $summary       = !empty($job['summary'])
            ? json_decode($job['summary'], true)
            : array(
                'shows_synced' => 0, 'shows_failed' => array(), 'shows_pending' => 0, 'episodes_watched' => 0, 'episodes_rewatched' => 0,
                'lists_created' => 0, 'list_series_added' => 0, 'list_movies_added' => 0,
                'list_series_pending' => 0, 'list_movies_pending' => 0,
                'movies_synced' => 0, 'movies_unmatched' => array(),
                'movies_pending' => 0, 'movies_watched' => 0, 'movies_rewatched' => 0,
                'shows_total' => 0, 'movies_total' => 0,
            );
        $mergedSummary = array(
            'shows_total'          => $summaryDelta['shows_total'],
            'movies_total'         => $summaryDelta['movies_total'],
            'shows_synced'         => $summary['shows_synced'] + $summaryDelta['shows_synced'],
            'shows_failed'         => array_values(array_unique(array_merge($summary['shows_failed'], $summaryDelta['shows_failed']))),
            'shows_pending'        => ($summary['shows_pending'] ?? 0) + $summaryDelta['shows_pending'],
            'episodes_watched'     => $summary['episodes_watched'] + $summaryDelta['episodes_watched'],
            'episodes_rewatched'   => ($summary['episodes_rewatched'] ?? 0) + $summaryDelta['episodes_rewatched'],
            'lists_created'        => ($summary['lists_created'] ?? 0) + $summaryDelta['lists_created'],
            'list_series_added'    => ($summary['list_series_added'] ?? 0) + $summaryDelta['list_series_added'],
            'list_movies_added'    => ($summary['list_movies_added'] ?? 0) + $summaryDelta['list_movies_added'],
            'list_series_pending'  => ($summary['list_series_pending'] ?? 0) + $summaryDelta['list_series_pending'],
            'list_movies_pending'  => ($summary['list_movies_pending'] ?? 0) + $summaryDelta['list_movies_pending'],
            'movies_synced'        => ($summary['movies_synced'] ?? 0) + $summaryDelta['movies_synced'],
            'movies_unmatched'     => array_values(array_unique(array_merge(
                $summary['movies_unmatched'] ?? array(),
                $summaryDelta['movies_unmatched']
            ))),
            'movies_pending'       => ($summary['movies_pending'] ?? 0) + $summaryDelta['movies_pending'],
            'movies_watched'       => ($summary['movies_watched'] ?? 0) + $summaryDelta['movies_watched'],
            'movies_rewatched'     => ($summary['movies_rewatched'] ?? 0) + $summaryDelta['movies_rewatched'],
        );

        $sql    = '
            UPDATE user_import
            SET processed_show_ids = :show_ids, processed_list_keys = :list_keys,
                processed_movie_keys = :movie_keys, summary = :summary
            WHERE id_user_import = :id
        ';
        $params = array(
            'id'         => array('value' => $id, 'type' => PDO::PARAM_INT),
            'show_ids'   => array('value' => json_encode($mergedShowIds), 'type' => PDO::PARAM_STR),
            'list_keys'  => array('value' => json_encode($mergedListKeys), 'type' => PDO::PARAM_STR),
            'movie_keys' => array('value' => json_encode($mergedMovieKeys), 'type' => PDO::PARAM_STR),
            'summary'    => array('value' => json_encode($mergedSummary), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    public function markFailed(int $id, string $error): void
    {
        $sql    = '
            UPDATE user_import
            SET status = "failed", error = :error
            WHERE id_user_import = :id
        ';
        $params = array(
            'id'    => array('value' => $id, 'type' => PDO::PARAM_INT),
            'error' => array('value' => $error, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    private function updateStatus(int $id, string $status): void
    {
        $sql    = '
            UPDATE user_import
            SET status = :status
            WHERE id_user_import = :id
        ';
        $params = array(
            'id'     => array('value' => $id, 'type' => PDO::PARAM_INT),
            'status' => array('value' => $status, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

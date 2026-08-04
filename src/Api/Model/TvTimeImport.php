<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * Job tracking for the TV Time GDPR-export importer. Processed by a
 * separate cron-triggered endpoint (Api\Controller\Import\TvTimeProcess)
 * rather than inline in the upload request, in resumable time-boxed
 * batches rather than one single call - syncing a whole TV Time history
 * from TheTVDB reliably outlasts even Apache's own reverse-proxy timeout,
 * confirmed empirically against a real ~970-show export.
 */
class TvTimeImport extends Model
{

    public function create(int $idUser, string $zipPath): int
    {
        $sql    = '
            INSERT INTO tvtime_import (id_user, zip_path)
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
     * Called from Api\Controller\Account\Delete (a job's zip/extracted
     * files would otherwise be left orphaned on disk once the account is
     * gone) and from Api\Controller\Import\TvTime, right before starting a
     * fresh import - without this, an old job (done, failed, or abandoned
     * mid-way) and whatever it left behind (movie_import_pending/
     * series_import_pending rows tied to it, tagged rewatch rows via
     * WatchedEpisode::syncRewatchesFromImport()) just piles up: confirmed
     * live this session, repeatedly, as stray pending-resolution rows
     * surviving a manual cleanup that only touched tvtime_import itself.
     * Deliberately doesn't touch user_serie_watchlist/user_episode_watched/
     * user_movie_watchlist/user_movie_watched/user_list - those are the
     * user's real, earned watch history from a *previous* import, not this
     * job-tracking bookkeeping, and must survive a fresh re-import.
     */
    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            SELECT id_tvtime_import, zip_path
            FROM tvtime_import
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $jobs   = $this->mysql->query($sql, $params);

        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            @unlink($job['zip_path']);
            $extractDir = dirname($job['zip_path']) . '/' . $job['id_tvtime_import'];
            if (is_dir($extractDir)) {
                foreach (glob($extractDir . '/*') ?: array() as $file) {
                    @unlink($file);
                }
                @rmdir($extractDir);
            }
        }

        $jobIdParams  = array();
        $placeholders = array();
        foreach (array_values(array_column($jobs, 'id_tvtime_import')) as $index => $jobId) {
            $key               = 'job_id_' . $index;
            $placeholders[]    = ':' . $key;
            $jobIdParams[$key] = array('value' => (int) $jobId, 'type' => PDO::PARAM_INT);
        }
        $inClause = implode(',', $placeholders);

        $this->mysql->query(
            "DELETE FROM movie_import_pending WHERE id_tvtime_import IN ($inClause)",
            $jobIdParams
        );
        $this->mysql->query(
            "DELETE FROM series_import_pending WHERE id_tvtime_import IN ($inClause)",
            $jobIdParams
        );

        $sql = '
            DELETE FROM tvtime_import
            WHERE id_user = :id_user
        ';
        $this->mysql->query($sql, $params);
    }

    public function findForUser(int $id, int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM tvtime_import
            WHERE id_tvtime_import = :id AND id_user = :id_user
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
     * this user's own latest not-yet-finished job, if any - lets the
     * Flutter app recover from having no idea an import is still running:
     * its own in-memory state (TvTimeImportController) doesn't survive the
     * app process restarting (e.g. the phone killing a backgrounded app),
     * so it can ask on its own instead of needing a remembered job id (see
     * Api\Controller\Import\TvTimeCurrent)
     */
    public function findLatestInProgressForUser(int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM tvtime_import
            WHERE id_user = :id_user AND status IN ("pending", "processing")
            ORDER BY created DESC
            LIMIT 1
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $rows   = $this->mysql->query($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * Advisory lock scoped to one job, so at most one request is ever
     * inside JobRunner::processOneBatch() for it at a time - more than one
     * client can legitimately try to advance the very same job
     * concurrently (the same import open in more than one tab/device, each
     * independently resuming it - see Api\Controller\Import\TvTimeCurrent -
     * or a poll racing the cron backstop). Without this, two overlapping
     * calls both read the same not-yet-updated "already done" snapshot and
     * redo the same shows - recordBatch()'s summary counts are summed, not
     * deduped like processed_show_ids is, so this silently double-counts
     * (confirmed live: shows_synced ended up higher than shows_total).
     * MySQL auto-releases a named lock when the acquiring connection
     * closes (end of this PHP request), so a crashed/killed request can't
     * leave a job stuck locked forever - no manual cleanup path needed.
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
        return 'tvtime_import_' . $id;
    }

    /**
     * the single oldest job that isn't finished yet (pending OR already
     * processing - a job stays 'processing' across every batch until
     * nothing's left, see Api\Controller\Import\TvTimeProcess), so a
     * repeated cron tick keeps resuming the same job rather than starting a
     * second one before the first is actually done
     */
    public function findNextToProcess(): ?array
    {
        $sql  = '
            SELECT *
            FROM tvtime_import
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
     * merges this batch's newly-done show ids/list keys/movie names and
     * summary counts into whatever's already recorded - read-then-write is
     * fine here since only one batch of one job is ever processed at a time
     * (no concurrent writers to race against)
     *
     * @param array<int>    $newDoneShowIds
     * @param array<string> $newDoneListKeys
     * @param array<string> $newDoneMovieKeys
     * @param array{
     *     shows_synced: int, shows_failed: array<int>, shows_pending: int, episodes_watched: int, episodes_rewatched: int,
     *     lists_created: int, list_series_added: int, list_movies_added: int, movies_synced: int, movies_unmatched: array<string>,
     *     movies_pending: int, movies_watched: int, movies_rewatched: int,
     *     shows_total: int, movies_total: int
     * } $summaryDelta shows_total/movies_total are the export's fixed
     *   totals (from re-parsing the same zip - same numbers every batch),
     *   not deltas - set directly rather than accumulated, unlike every
     *   other key here. Lets the client show a real (not fake/estimated)
     *   progress fraction - see Api\Model\TvTimeImport\JobRunner.
     */
    public function recordBatch(int $id, array $newDoneShowIds, array $newDoneListKeys, array $newDoneMovieKeys, array $summaryDelta): void
    {
        $sql    = '
            SELECT processed_show_ids, processed_list_keys, processed_movie_keys, summary
            FROM tvtime_import
            WHERE id_tvtime_import = :id
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
            UPDATE tvtime_import
            SET processed_show_ids = :show_ids, processed_list_keys = :list_keys,
                processed_movie_keys = :movie_keys, summary = :summary
            WHERE id_tvtime_import = :id
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
            UPDATE tvtime_import
            SET status = "failed", error = :error
            WHERE id_tvtime_import = :id
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
            UPDATE tvtime_import
            SET status = :status
            WHERE id_tvtime_import = :id
        ';
        $params = array(
            'id'     => array('value' => $id, 'type' => PDO::PARAM_INT),
            'status' => array('value' => $status, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

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
     * called from Api\Controller\Account\Delete - a job's zip/extracted
     * files would otherwise be left orphaned on disk once the account (and
     * this row) is gone
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
     * merges this batch's newly-done show ids/list keys and summary counts
     * into whatever's already recorded - read-then-write is fine here since
     * only one batch of one job is ever processed at a time (no concurrent
     * writers to race against)
     *
     * @param array<int>    $newDoneShowIds
     * @param array<string> $newDoneListKeys
     * @param array{
     *     shows_synced: int, shows_failed: array<int>, episodes_watched: int, episodes_rewatched: int,
     *     lists_created: int, list_series_added: int
     * } $summaryDelta
     */
    public function recordBatch(int $id, array $newDoneShowIds, array $newDoneListKeys, array $summaryDelta): void
    {
        $sql    = '
            SELECT processed_show_ids, processed_list_keys, summary
            FROM tvtime_import
            WHERE id_tvtime_import = :id
        ';
        $params = array('id' => array('value' => $id, 'type' => PDO::PARAM_INT));
        $job    = $this->mysql->query($sql, $params)[0] ?? array();

        $mergedShowIds  = array_values(array_unique(array_merge($this->getProcessedShowIds($job), $newDoneShowIds)));
        $mergedListKeys = array_values(array_unique(array_merge($this->getProcessedListKeys($job), $newDoneListKeys)));

        $summary       = !empty($job['summary'])
            ? json_decode($job['summary'], true)
            : array(
                'shows_synced' => 0, 'shows_failed' => array(), 'episodes_watched' => 0, 'episodes_rewatched' => 0,
                'lists_created' => 0, 'list_series_added' => 0,
            );
        $mergedSummary = array(
            'shows_synced'       => $summary['shows_synced'] + $summaryDelta['shows_synced'],
            'shows_failed'       => array_values(array_unique(array_merge($summary['shows_failed'], $summaryDelta['shows_failed']))),
            'episodes_watched'   => $summary['episodes_watched'] + $summaryDelta['episodes_watched'],
            'episodes_rewatched' => ($summary['episodes_rewatched'] ?? 0) + $summaryDelta['episodes_rewatched'],
            'lists_created'      => ($summary['lists_created'] ?? 0) + $summaryDelta['lists_created'],
            'list_series_added'  => ($summary['list_series_added'] ?? 0) + $summaryDelta['list_series_added'],
        );

        $sql    = '
            UPDATE tvtime_import
            SET processed_show_ids = :show_ids, processed_list_keys = :list_keys, summary = :summary
            WHERE id_tvtime_import = :id
        ';
        $params = array(
            'id'        => array('value' => $id, 'type' => PDO::PARAM_INT),
            'show_ids'  => array('value' => json_encode($mergedShowIds), 'type' => PDO::PARAM_STR),
            'list_keys' => array('value' => json_encode($mergedListKeys), 'type' => PDO::PARAM_STR),
            'summary'   => array('value' => json_encode($mergedSummary), 'type' => PDO::PARAM_STR),
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

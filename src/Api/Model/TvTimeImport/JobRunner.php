<?php

namespace Api\Model\TvTimeImport;

use Api\Model\TheTvdb\Client;
use Api\Model\TvTimeImport as TvTimeImportModel;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Advances one time-boxed batch of a job (see Processor::TIME_BUDGET_SECONDS):
 * extract, parse, process, record, and mark done/failed if finished.
 * Triggered by the app's poll loop (the primary driver) or the cron
 * backstop for a job whose owner closed the app early.
 */
final class JobRunner
{

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $job a row from TvTimeImport with status
     *                                  'pending' or 'processing'
     * @return bool whether the whole job finished as a result of this batch
     *              (always false if another request is already advancing
     *              it - see acquireProcessingLock())
     */
    public function processOneBatch(array $job): bool
    {
        // safety margin under Processor::TIME_BUDGET_SECONDS; no-op if the
        // host disables this function. 55s leaves 5s under Cdmon's 60s
        // max_execution_time.
        @set_time_limit(55);

        $importModel = new TvTimeImportModel();
        $id          = (int) $job['id_user_import'];

        if (!$importModel->acquireProcessingLock($id)) {
            // another request is already advancing this job - back off
            // rather than redo work against a stale snapshot
            return false;
        }

        // a fatal error (timeout, OOM) isn't a catchable Throwable below;
        // without this the row was left stuck on 'processing' with no
        // explanation (happened in production). error_clear_last() avoids
        // attributing a stale error to this batch; $batchInProgress skips
        // this once the batch already finished normally (the shutdown
        // function runs at the very end of the request). Can't catch an
        // OS-level kill (OOM killer, Apache's hard timeout) - best effort.
        error_clear_last();
        $batchInProgress = true;
        register_shutdown_function(static function () use ($importModel, $id, &$batchInProgress): void {
            if (!$batchInProgress) {
                return;
            }
            $error = error_get_last();
            $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
            if ($error !== null && in_array($error['type'], $fatal, true)) {
                $importModel->markFailed(
                    $id,
                    $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
                );
            }
        });

        try {
            if ($job['status'] === 'pending') {
                $importModel->markProcessing($id);
            }

            $finished   = false;
            $extractDir = null;
            try {
                $extractDir        = $this->extract($job['zip_path'], $id);
                $parsed            = (new Parser())->parse($extractDir);
                $alreadyDoneShows  = $importModel->getProcessedShowIds($job);
                $alreadyDoneLists  = $importModel->getProcessedListKeys($job);
                $alreadyDoneMovies = $importModel->getProcessedMovieKeys($job);
                $batch             = (new Processor($this->client))
                    ->processBatch((int) $job['id_user'], $id, $parsed, $alreadyDoneShows, $alreadyDoneLists, $alreadyDoneMovies);

                $importModel->recordBatch($id, $batch['done_show_ids'], $batch['done_list_keys'], $batch['done_movie_keys'], array(
                    'shows_synced'        => $batch['shows_synced'],
                    'shows_failed'        => $batch['shows_failed'],
                    'shows_pending'       => $batch['shows_pending'],
                    'episodes_watched'    => $batch['episodes_watched'],
                    'episodes_rewatched'  => $batch['episodes_rewatched'],
                    'lists_created'       => $batch['lists_created'],
                    'list_series_added'   => $batch['list_series_added'],
                    'list_movies_added'   => $batch['list_movies_added'],
                    'list_series_pending' => $batch['list_series_pending'],
                    'list_movies_pending' => $batch['list_movies_pending'],
                    'movies_synced'       => $batch['movies_synced'],
                    'movies_unmatched'    => $batch['movies_unmatched'],
                    'movies_pending'      => $batch['movies_pending'],
                    'movies_watched'      => $batch['movies_watched'],
                    'movies_rewatched'    => $batch['movies_rewatched'],
                    // fixed totals from this re-parse, for a real client-side progress fraction
                    'shows_total'         => count($parsed['shows']),
                    'movies_total'        => count($parsed['movies']),
                ));

                $finished = $batch['finished'];
                if ($finished) {
                    $importModel->markDone($id);
                    @unlink($job['zip_path']);
                }
            } catch (Throwable $e) {
                $importModel->markFailed($id, $e->getMessage());
                @unlink($job['zip_path']);
            } finally {
                $this->removeExtractedFiles($extractDir);
            }

            return $finished;
        } finally {
            $batchInProgress = false;
            $importModel->releaseProcessingLock($id);
        }
    }

    private function extract(string $zipPath, int $jobId): string
    {
        $dir = dirname($zipPath) . '/' . $jobId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the uploaded zip.');
        }
        $zip->extractTo($dir);
        $zip->close();

        return $dir;
    }

    // only the extracted CSVs - the zip itself is kept until the job
    // finishes/fails, since it's needed again on the next batch
    private function removeExtractedFiles(?string $dir): void
    {
        if ($dir === null || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: array() as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

}

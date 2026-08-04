<?php

namespace Api\Model\TvTimeImport;

use Api\Model\TheTvdb\Client;
use Api\Model\TvTimeImport as TvTimeImportModel;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Advances one time-boxed batch of one job (see Processor::TIME_BUDGET_SECONDS)
 * - extract, parse, process, record, and mark done/failed if this batch
 * finished it. Shared by the two things that can trigger a batch:
 * Api\Controller\Import\TvTimeStatus (the Flutter app's own poll loop,
 * already running every few seconds while the import screen is open - this
 * is the primary driver, needs no external infrastructure at all) and
 * Api\Controller\Import\TvTimeProcess (a system cron, kept only as a
 * backstop for a job whose owner closed the app before it finished).
 */
final class JobRunner
{

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $job a row from TvTimeImport with status
     *                                  'pending' or 'processing' - the
     *                                  caller is responsible for not calling
     *                                  this on an already done/failed job
     * @return bool whether the whole job finished as a result of this batch
     *              (always false if another request is already advancing
     *              it right now - see acquireProcessingLock())
     */
    public function processOneBatch(array $job): bool
    {
        // best-effort extra safety margin on top of Processor's own
        // TIME_BUDGET_SECONDS (see that constant's own docblock on why it
        // matters) - a no-op if the host disables this function, which
        // some shared hosting does, but harmless either way
        @set_time_limit(30);

        $importModel = new TvTimeImportModel();
        $id          = (int) $job['id_tvtime_import'];

        if (!$importModel->acquireProcessingLock($id)) {
            // another request (another tab/device polling this same job,
            // or the cron backstop) is already inside this method for this
            // job right now - back off rather than redo the same work
            // against a stale "already done" snapshot
            return false;
        }

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
                    // the export's fixed totals, from this same re-parse -
                    // lets the client show a real progress fraction instead
                    // of a plain spinner (see TvTimeImport::recordBatch()'s
                    // own docblock)
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

    // only the extracted CSVs, re-created fresh every call - the zip
    // itself is kept until the job actually finishes (or fails), since it's
    // needed again on the next batch
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

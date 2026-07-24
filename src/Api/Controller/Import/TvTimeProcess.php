<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Client;
use Api\Model\TvTimeImport;
use Api\Model\TvTimeImport\Parser;
use Api\Model\TvTimeImport\Processor;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Meant to be pinged periodically by a system cron (this framework has no
 * queue/worker infra - see freimguork-core's Cronjob sub-project convention
 * for the same "background work via an HTTP-triggered endpoint" pattern).
 *
 * Processes one time-boxed batch of the oldest not-yet-finished job per
 * call (see Processor::TIME_BUDGET_SECONDS) rather than the whole thing at
 * once - confirmed empirically that a real ~970-show import outlasts
 * Apache's own 60s reverse-proxy timeout well before finishing. The zip is
 * re-extracted and the CSVs re-parsed on every call (cheap - local file
 * reads, no network - unlike the TheTVDB sync itself); only which shows are
 * already done (Api\Model\TvTimeImport::processed_show_ids) is persisted
 * between calls.
 */
#[Route('/import/tvtime/process', methods: ['POST'], name: 'api.import.tvtime.process')]
class TvTimeProcess extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    // no real user for a cron-triggered call - authenticated with the app's
    // own shared secret instead, same convention as Register/Login
    protected function requiresUserToken(): bool
    {
        return false;
    }

    protected function run(): void
    {
        $importModel = new TvTimeImport();
        $job         = $importModel->findNextToProcess();
        if ($job === null) {
            $this->assign('processed', false);
            return;
        }

        $id = (int) $job['id_tvtime_import'];
        if ($job['status'] === 'pending') {
            $importModel->markProcessing($id);
        }

        $extractDir = null;
        try {
            $extractDir  = $this->extract($job['zip_path'], $id);
            $parsed      = (new Parser())->parse($extractDir);
            $alreadyDone = $importModel->getProcessedShowIds($job);
            $batch       = (new Processor($this->client))->processBatch((int) $job['id_user'], $parsed, $alreadyDone);

            $importModel->recordBatch($id, $batch['done_show_ids'], array(
                'shows_synced'     => $batch['shows_synced'],
                'shows_failed'     => $batch['shows_failed'],
                'episodes_watched' => $batch['episodes_watched'],
            ));

            if ($batch['finished']) {
                $importModel->markDone($id);
                @unlink($job['zip_path']);
            }

            $this->assign('finished', $batch['finished']);
        } catch (Throwable $e) {
            $importModel->markFailed($id, $e->getMessage());
            @unlink($job['zip_path']);
        } finally {
            $this->removeExtractedFiles($extractDir);
        }

        $this->assign('processed', true);
        $this->assign('id', $id);
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

<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Client;
use Api\Model\TvTimeImport;
use Api\Model\TvTimeImport\JobRunner;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * The primary driver of an import, not just a status read - the Flutter app
 * already polls this every few seconds while the import screen is open (see
 * TvTimeImportController._poll() client-side), so a still-pending/processing
 * job gets one more time-boxed batch (see JobRunner) advanced right here
 * before its (now fresher) status is reported back, with no external cron
 * needed for the common case. Scoped to this specific job, not whichever
 * job happens to be globally oldest (unlike Api\Controller\Import\
 * TvTimeProcess, the cron-only backstop for an abandoned import) - so two
 * users importing at the same time each drive their own job forward from
 * their own poll, rather than one racing ahead of the other.
 */
#[Route('/import/tvtime/{id}', methods: ['GET'], name: 'api.import.tvtime.status', requirements: ['id' => '\d+'])]
class TvTimeStatus extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $id          = (int) $this->getParam('id');
        $importModel = new TvTimeImport();
        $job         = $importModel->findForUser($id, $this->user->getID());
        if ($job === null) {
            $this->error = '404';
            return;
        }

        if (in_array($job['status'], array('pending', 'processing'), true)) {
            (new JobRunner($this->client))->processOneBatch($job);
            $job = $importModel->findForUser($id, $this->user->getID());
        }

        $this->assign('status', $job['status']);
        $this->assign('summary', $job['summary'] !== null ? json_decode($job['summary'], true) : null);
        $this->assign('error_message', $job['error']);
    }

}

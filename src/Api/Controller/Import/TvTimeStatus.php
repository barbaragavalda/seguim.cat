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
 * The primary driver of an import, not just a status read - the app polls
 * this every few seconds while the screen is open, so a pending/processing
 * job gets one more time-boxed batch advanced here before its (fresher)
 * status is reported, with no cron needed for the common case. Scoped to
 * this one job (unlike TvTimeProcess, the cron-only backstop) so concurrent
 * imports each advance from their own poll.
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

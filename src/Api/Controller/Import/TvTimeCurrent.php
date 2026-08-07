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
 * Lets the app recover an in-progress import it has no memory of (e.g.
 * after a backgrounded app process gets killed) - unlike TvTimeStatus
 * (which needs an id already), this finds the user's latest not-yet-
 * finished job by itself, and advances it one batch before reporting, same
 * as TvTimeStatus.
 */
#[Route('/import/tvtime/current', methods: ['GET'], name: 'api.import.tvtime.current')]
class TvTimeCurrent extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $importModel = new TvTimeImport();
        $job         = $importModel->findLatestInProgressForUser($this->user->getID());
        if ($job === null) {
            $this->assign('id', null);
            return;
        }

        (new JobRunner($this->client))->processOneBatch($job);
        $job = $importModel->findForUser((int) $job['id_user_import'], $this->user->getID());

        $this->assign('id', (int) $job['id_user_import']);
        $this->assign('status', $job['status']);
        $this->assign('summary', $job['summary'] !== null ? json_decode($job['summary'], true) : null);
        $this->assign('error_message', $job['error']);
    }

}

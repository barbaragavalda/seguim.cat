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
 * A backstop, not the primary driver - TvTimeStatus (the app's own poll
 * loop) advances jobs while the import screen is open; this only matters
 * if the owner closed the app first. Meant to be cron-pinged (no
 * queue/worker infra here - see freimguork-core's Cronjob convention) -
 * accepts GET because that's all Cdmon's URL-based cron can send, hence
 * checkToken()'s override below. Time-boxed per call (see
 * Processor::TIME_BUDGET_SECONDS): a real ~970-show import outlasts
 * Apache's 60s reverse-proxy timeout otherwise.
 */
#[Route('/import/tvtime/process', methods: ['GET', 'POST'], name: 'api.import.tvtime.process')]
class TvTimeProcess extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    // no real user for a cron-triggered call - authenticated via shared secret, same as Register/Login
    protected function requiresUserToken(): bool
    {
        return false;
    }

    /**
     * checkToken() only reads the shared secret from the Authorization
     * header, which a bare "visit this URL" cron can't send. Accept it as
     * `?token=` too, just here - reusing default_token since it's already
     * shipped inside the published Flutter app, not a tightly-held secret.
     */
    protected function checkToken(): bool|int
    {
        $webserviceConfig = $this->config->get('webservice');
        $defaultToken      = $webserviceConfig['default_token'] ?? null;
        if ($defaultToken !== null && ($_GET['token'] ?? null) === $defaultToken) {
            return false;
        }
        return parent::checkToken();
    }

    protected function run(): void
    {
        $importModel = new TvTimeImport();
        $job         = $importModel->findNextToProcess();
        if ($job === null) {
            $this->assign('processed', false);
            return;
        }

        $id       = (int) $job['id_user_import'];
        $finished = (new JobRunner($this->client))->processOneBatch($job);

        $this->assign('processed', true);
        $this->assign('finished', $finished);
        $this->assign('id', $id);
    }

}

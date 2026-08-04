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
 * A backstop, not the primary driver - Api\Controller\Import\TvTimeStatus
 * (the Flutter app's own poll loop, already running every few seconds while
 * the import screen is open) advances a job itself, tick by tick, with no
 * external infrastructure needed. This endpoint only matters for a job
 * whose owner closed the app (or lost connectivity) before it finished -
 * meant to be pinged occasionally by a system cron (this framework has no
 * queue/worker infra - see freimguork-core's Cronjob sub-project convention
 * for the same "background work via an HTTP-triggered endpoint" pattern).
 * In production this is Cdmon's own "visit a URL on a schedule" cron
 * feature - GET only, no custom headers - hence both accepting GET (not
 * just POST) and checkToken()'s own override below.
 *
 * Processes one time-boxed batch of the globally oldest not-yet-finished
 * job per call (see Processor::TIME_BUDGET_SECONDS) rather than the whole
 * thing at once - confirmed empirically that a real ~970-show import
 * outlasts Apache's own 60s reverse-proxy timeout well before finishing.
 */
#[Route('/import/tvtime/process', methods: ['GET', 'POST'], name: 'api.import.tvtime.process')]
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

    /**
     * WebserviceController::checkToken() only ever reads the shared secret
     * from the Authorization header, which a bare "visit this URL" cron
     * (Cdmon's own scheduler) can't send. Accept it as a `?token=` query
     * parameter too, just for this one cron-triggered action - reusing the
     * app's existing default_token rather than a dedicated cron secret,
     * since default_token is already shipped inside the published Flutter
     * app itself and isn't a tightly-held value to begin with.
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

        $id       = (int) $job['id_tvtime_import'];
        $finished = (new JobRunner($this->client))->processOneBatch($job);

        $this->assign('processed', true);
        $this->assign('finished', $finished);
        $this->assign('id', $id);
    }

}

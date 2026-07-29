<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\SeriesImportPending;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * The user picked which TheTVDB series a pending show actually is (from the
 * candidates shown, or any other tvdb_id if they searched separately) -
 * syncs each and replays the watchlist/watched/rewatch state that was
 * snapshotted at import time. See Api\Model\SeriesImportPending::resolve()
 */
#[Route('/import/series/pending/{id}/resolve', methods: ['POST'], name: 'api.import.series.pending.resolve', requirements: ['id' => '\d+'])]
class ResolvePendingSeries extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $id = (int) $this->getParam('id');

        // accepts either tvdb_ids[] (one or more) or a single tvdb_id, same
        // request shape as ResolvePendingMovie
        $rawIds = $_POST['tvdb_ids'] ?? (isset($_POST['tvdb_id']) ? array($_POST['tvdb_id']) : array());
        if (!is_array($rawIds)) {
            $rawIds = array($rawIds);
        }
        $tvdbIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn(int $v): bool => $v > 0)));

        if (empty($tvdbIds)) {
            $this->error = 'At least one tvdb_id is required.';
            return;
        }

        $resolved = (new SeriesImportPending())->resolve($id, $this->user->getID(), $tvdbIds, $this->client);
        if ($resolved === null) {
            $this->error = '404';
        } elseif ($resolved === false) {
            // the pending row is still there (see resolve()'s own docblock) -
            // the user can pick a different candidate, or try the same one
            // again once TheTVDB's data catches up
            $this->error = 'candidate_unavailable';
        }
    }

}

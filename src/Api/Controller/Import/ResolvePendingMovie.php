<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\MovieImportPending;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * The user picked which TheTVDB movie(s) a pending title actually is (from
 * the candidates shown, or any other tvdb_id if they searched separately) -
 * more than one when TV Time's own single entry actually represents several
 * real movies it couldn't tell apart (e.g. "Mulan" 1998 and 2020, both
 * watched) - syncs each and replays the watchlist/watched/rewatch state that
 * was snapshotted at import time, same as a normal confident match would
 * have gotten. See Api\Model\MovieImportPending::resolve()
 */
#[Route('/import/movies/pending/{id}/resolve', methods: ['POST'], name: 'api.import.movies.pending.resolve', requirements: ['id' => '\d+'])]
class ResolvePendingMovie extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $id = (int) $this->getParam('id');

        // accepts either tvdb_ids[] (one or more, the normal case from the
        // resolution screen) or a single tvdb_id, for a lighter request
        // when there's only one choice
        $rawIds = $_POST['tvdb_ids'] ?? (isset($_POST['tvdb_id']) ? array($_POST['tvdb_id']) : array());
        if (!is_array($rawIds)) {
            $rawIds = array($rawIds);
        }
        $tvdbIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn(int $v): bool => $v > 0)));

        if (empty($tvdbIds)) {
            $this->error = 'At least one tvdb_id is required.';
            return;
        }

        $resolved = (new MovieImportPending())->resolve($id, $this->user->getID(), $tvdbIds, $this->client);
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

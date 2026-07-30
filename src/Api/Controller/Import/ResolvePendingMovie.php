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
 * real movies it couldn't tell apart (e.g. "Mulan" 1998 and 2020) - syncs
 * each and replays the watchlist state that was snapshotted at import time,
 * same as a normal confident match would have gotten. Split into two lists
 * (watched_tvdb_ids[]/pending_tvdb_ids[]) rather than one, so the resolution
 * screen can let the user say "I watched this one, not that one" for a
 * title the source data alone can't disambiguate - see
 * Api\Model\MovieImportPending::resolve()
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

        // accepts either the *_tvdb_ids[] pair (the normal case from the
        // resolution screen), or a single tvdb_id (treated as watched) for
        // a lighter request when there's only one choice
        $watchedTvdbIds = $this->intIds($_POST['watched_tvdb_ids'] ?? (isset($_POST['tvdb_id']) ? array($_POST['tvdb_id']) : array()));
        $pendingTvdbIds = $this->intIds($_POST['pending_tvdb_ids'] ?? array());

        if (empty($watchedTvdbIds) && empty($pendingTvdbIds)) {
            $this->error = 'At least one tvdb_id is required.';
            return;
        }

        $resolved = (new MovieImportPending())->resolve($id, $this->user->getID(), $watchedTvdbIds, $pendingTvdbIds, $this->client);
        if ($resolved === null) {
            $this->error = '404';
        } elseif ($resolved === false) {
            // the pending row is still there (see resolve()'s own docblock) -
            // the user can pick a different candidate, or try the same one
            // again once TheTVDB's data catches up
            $this->error = 'candidate_unavailable';
        }
    }

    /**
     * @return array<int, int>
     */
    private function intIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = array($raw);
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw), fn(int $v): bool => $v > 0)));
    }

}

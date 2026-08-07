<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\MovieImportPending;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * The user picked which TheTVDB movie(s) a pending title actually is - more
 * than one when TV Time's entry conflates several real movies it couldn't
 * tell apart (e.g. "Mulan" 1998 and 2020). Two lists (watched_tvdb_ids[]/
 * pending_tvdb_ids[]) let the screen say "I watched this one, not that one" -
 * see Api\Model\MovieImportPending::resolve()
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

        // accepts *_tvdb_ids[] (normal case), or a single tvdb_id (treated as
        // watched) for a lighter request when there's only one choice
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
            // row is still pending (see resolve()'s docblock) - user can retry
            // or pick a different candidate once TheTVDB's data catches up
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

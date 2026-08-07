<?php

namespace Api\Controller\Search;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\Series;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * Series\Search and Movie\Search stay type-scoped for flows that only make
 * sense for one kind (e.g. adding a series to a user_list) - this is the
 * general-purpose one. Each result carries its own `type` so the client
 * can route without a second lookup.
 */
#[Route('/search', methods: ['GET'], name: 'api.search')]
class Search extends Controller
{

    private const int CACHE_TTL_SECONDS = 600;

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function requiresUserToken(): bool
    {
        return false;
    }

    protected function run(): void
    {
        $query = trim((string) ($_GET['query'] ?? ''));
        if (!$query) {
            $this->error = 'A search query is required.';
            return;
        }
        $page = max(0, (int) ($_GET['page'] ?? 0));

        $tvdbLanguageCode = Languages::tvdbCodeForCulture($this->config->getLanguage()) ?? 'eng';

        // cached unpersonalized - watch progress is enriched below, after the cache
        $result = $this->cached(
            'unified-search:' . $tvdbLanguageCode . ':' . $page . ':' . $query,
            self::CACHE_TTL_SECONDS,
            fn(): array => $this->client->searchAll($query, $page, $tvdbLanguageCode),
        );
        $results = $result['results'];

        // only true when a real user token was sent (requiresUserToken() is false so the
        // shared one is also accepted) - no user means no watch progress to compute
        if ($this->user !== null) {
            $results = $this->withWatchProgress($results);
        }

        $this->assign('results', $results);
        $this->assign('hasMore', $result['hasMore']);
    }

    /**
     * Attaches watched/total episodes to already-synced series results only
     * - the client treats a missing pair as "no progress bar", not "0%".
     */
    private function withWatchProgress(array $results): array
    {
        $tvdbIds = array_values(array_unique(array_map(
            static fn(array $r): int => (int) $r['tvdb_id'],
            array_filter($results, static fn(array $r): bool => ($r['type'] ?? null) === 'series'),
        )));
        if (empty($tvdbIds)) {
            return $results;
        }

        $idBySeries = (new Series())->idsForTvdbIds($tvdbIds);
        if (empty($idBySeries)) {
            return $results;
        }

        $progress = (new Episode())->watchProgressForSeries($this->user->getID(), array_values($idBySeries));

        foreach ($results as &$result) {
            $tvdbId  = (int) $result['tvdb_id'];
            $idSerie = $idBySeries[$tvdbId] ?? null;
            if ($idSerie !== null && isset($progress[$idSerie])) {
                $result['watched_episodes'] = $progress[$idSerie]['watched'];
                $result['total_episodes']   = $progress[$idSerie]['total'];
            }
        }
        unset($result);

        return $results;
    }

}

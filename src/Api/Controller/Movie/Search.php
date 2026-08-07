<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/movies/search', methods: ['GET'], name: 'api.movies.search')]
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

        $result = $this->cached(
            'movie-search:' . $tvdbLanguageCode . ':' . $page . ':' . $query,
            self::CACHE_TTL_SECONDS,
            fn(): array => $this->client->searchMovies($query, $page, $tvdbLanguageCode),
        );
        $this->assign('results', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

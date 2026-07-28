<?php

namespace Api\Controller\Search;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * Unified series+movie search backing the app's single search screen -
 * Series\Search (/series/search) and Movie\Search (/movies/search) stay
 * type-scoped for flows that only make sense for one kind (e.g. adding a
 * series to a user_list), this is the general-purpose one. Each result
 * already carries its own `type` ("series"/"movie") so the client can route
 * to the right detail screen without a second lookup
 */
#[Route('/search', methods: ['GET'], name: 'api.search')]
class Search extends Controller
{

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

        $result = $this->client->searchAll($query, $page, $tvdbLanguageCode);
        $this->assign('results', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

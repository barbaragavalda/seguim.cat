<?php

namespace Api\Controller\Series;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/series/search', methods: ['GET'], name: 'api.series.search')]
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

        // falls back to English (TheTVDB's most-complete language) for an unresolved/
        // unsupported culture, rather than leaving name/overview unset
        $tvdbLanguageCode = Languages::tvdbCodeForCulture($this->config->getLanguage()) ?? 'eng';

        $result = $this->client->search($query, $page, $tvdbLanguageCode);
        $this->assign('results', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

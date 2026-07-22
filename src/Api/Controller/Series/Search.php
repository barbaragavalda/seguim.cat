<?php

namespace Api\Controller\Series;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Client;
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

        $result = $this->client->search($query, $page);
        $this->assign('results', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

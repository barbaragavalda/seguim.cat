<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\SerieLang;
use Api\Model\TheTvdb\Client;
use Api\Model\Watchlist;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/watchlist/{tvdbId}', methods: ['POST'], name: 'api.watchlist.add', requirements: ['tvdbId' => '\d+'])]
class Add extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        // sync() first so user_serie_watchlist always points at a real row, even on first touch
        $series = new Series();
        $info   = $series->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        // so the watchlist has a name/overview ready immediately, not only after the series'
        // own detail endpoint is first opened
        (new SerieLang())->syncForLanguage($info['id_serie'], $tvdbId, $this->config->getLanguage(), $this->client);

        (new Watchlist())->add($this->user->getID(), $info['id_serie']);
    }

}

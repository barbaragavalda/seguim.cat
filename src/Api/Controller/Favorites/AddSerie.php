<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\SerieFavorite;
use Api\Model\Series;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/** Independent of Watchlist membership - see SerieFavorite's own docblock */
#[Route('/favorites/series/{tvdbId}', methods: ['POST'], name: 'api.favorites.add_serie', requirements: ['tvdbId' => '\d+'])]
class AddSerie extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        // sync() first so the favorite row always points at a real local series, same as Watchlist\Add
        $series = new Series();
        $info   = $series->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        (new SerieFavorite())->add($this->user->getID(), $info['id_serie']);
    }

}

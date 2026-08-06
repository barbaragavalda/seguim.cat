<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\SerieFavorite;
use Api\Model\Series;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/**
 * Independent of Api\Model\Watchlist membership - the heart toggle on
 * Series\Detail works whether or not the series is being tracked, see
 * Api\Model\SerieFavorite's own docblock.
 */
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

        // sync() first so user_serie_favorite always points at a real
        // local series row, same reasoning as Watchlist\Add
        $series = new Series();
        $info   = $series->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        (new SerieFavorite())->add($this->user->getID(), $info['id_serie']);
    }

}

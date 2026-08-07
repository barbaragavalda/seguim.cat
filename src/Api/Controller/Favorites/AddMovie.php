<?php

namespace Api\Controller\Favorites;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\MovieFavorite;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

/** Independent of MovieWatchlist membership - see AddSerie's own docblock */
#[Route('/favorites/movies/{tvdbId}', methods: ['POST'], name: 'api.favorites.add_movie', requirements: ['tvdbId' => '\d+'])]
class AddMovie extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new Movie();
        $info  = $movie->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        (new MovieFavorite())->add($this->user->getID(), $info['id_movie']);
    }

}

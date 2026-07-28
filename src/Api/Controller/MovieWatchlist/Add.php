<?php

namespace Api\Controller\MovieWatchlist;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\MovieLang;
use Api\Model\MovieWatchlist;
use Api\Model\TheTvdb\Client;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/movies/{tvdbId}/watchlist', methods: ['POST'], name: 'api.movie_watchlist.add', requirements: ['tvdbId' => '\d+'])]
class Add extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $tvdbId = (int) $this->getParam('tvdbId');

        // sync() first so user_movie_watchlist always points at a real
        // local movie row, even if this is the first time anyone's touched
        // it - same reasoning as Watchlist\Add for series
        $movie = new Movie();
        $info  = $movie->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        // so the watchlist has a name/overview ready in the user's own
        // language immediately, instead of only after they first open this
        // movie's own detail endpoint
        (new MovieLang())->syncForLanguage($info['id_movie'], $tvdbId, $this->config->getLanguage(), $this->client);

        (new MovieWatchlist())->add($this->user->getID(), $info['id_movie']);
    }

}

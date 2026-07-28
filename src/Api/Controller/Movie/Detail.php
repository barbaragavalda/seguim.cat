<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\MovieLang;
use Api\Model\MovieWatchlist;
use Api\Model\TheTvdb\Client;
use Api\Model\WatchedMovie;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/movies/{tvdbId}', methods: ['GET'], name: 'api.movies.detail', requirements: ['tvdbId' => '\d+'])]
class Detail extends Controller
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
        $tvdbId = (int) $this->getParam('tvdbId');

        $movie = new MovieModel();
        $info  = $movie->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        // Config::getLanguage() is already resolved per-request, same as
        // Series\Detail - only that one language's translation is fetched/
        // returned, not every language this app supports
        $culture = $this->config->getLanguage();

        // fall back to TheTVDB's own base/extended record (default_name/
        // default_overview) when the app's current language has no
        // translation, rather than showing a blank title/overview - same
        // pattern as Series\Detail
        $translation      = (new MovieLang())->syncForLanguage($info['id_movie'], $tvdbId, $culture, $this->client);
        $info['name']     = $translation['name'] ?: $info['default_name'];
        $info['overview'] = $translation['overview'] ?: $info['default_overview'];

        // $this->user is null for an anonymous request (default_token, no
        // real user logged in) - movie detail itself is public, only the
        // per-user watchlist/watched flags need a real user, same as
        // Series\Detail
        $inWatchlist = $this->user !== null
            ? (new MovieWatchlist())->has($this->user->getID(), $info['id_movie'])
            : false;
        $watchCount  = $this->user !== null
            ? (new WatchedMovie())->watchCount($this->user->getID(), $info['id_movie'])
            : 0;

        $this->assign('movie', $info);
        $this->assign('in_watchlist', $inWatchlist);
        $this->assign('watched', $watchCount > 0);
        $this->assign('watch_count', $watchCount);
    }

}

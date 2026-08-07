<?php

namespace Api\Controller\Movie;

use Api\Controller\Controller;
use Api\Model\Movie as MovieModel;
use Api\Model\MovieCast;
use Api\Model\MovieContentRating;
use Api\Model\MovieFavorite;
use Api\Model\MovieGenre;
use Api\Model\MovieLang;
use Api\Model\MovieTrailer;
use Api\Model\MovieWatchlist;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
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

        // only that one language's translation is fetched, not every language this app supports
        $culture = $this->config->getLanguage();

        // fall back to TheTVDB's base record (default_name/default_overview) when there's no
        // translation for the app's language, rather than a blank title/overview
        $translation      = (new MovieLang())->syncForLanguage($info['id_movie'], $tvdbId, $culture, $this->client);
        $info['name']     = $translation['name'] ?: $info['default_name'];
        $info['overview'] = $translation['overview'] ?: $info['default_overview'];

        // none of these have per-language text to fall back on - see their own docblocks.
        // Only genre labels vary by culture, handled client-side (l10n by slug), not here
        $info['genres']         = (new MovieGenre())->forMovie($info['id_movie']);
        $info['cast']           = (new MovieCast())->forMovie($info['id_movie']);
        $info['content_rating'] = (new MovieContentRating())->bestForCountry(
            $info['id_movie'],
            Languages::tvdbCountryForCulture($culture),
        );
        $info['trailer'] = (new MovieTrailer())->bestForLanguage(
            $info['id_movie'],
            Languages::tvdbCodeForCulture($culture),
        );

        // $this->user is null for an anonymous request - movie detail is public, only the
        // per-user watchlist/watched flags need a real user
        $inWatchlist = $this->user !== null
            ? (new MovieWatchlist())->has($this->user->getID(), $info['id_movie'])
            : false;
        $watchCount  = $this->user !== null
            ? (new WatchedMovie())->watchCount($this->user->getID(), $info['id_movie'])
            : 0;
        // independent of the watchlist flag above - see Series\Detail's own comment
        $isFavorite  = $this->user !== null
            ? (new MovieFavorite())->has($this->user->getID(), $info['id_movie'])
            : false;

        $this->assign('movie', $info);
        $this->assign('in_watchlist', $inWatchlist);
        $this->assign('watched', $watchCount > 0);
        $this->assign('watch_count', $watchCount);
        $this->assign('is_favorite', $isFavorite);
    }

}

<?php

namespace Api\Controller\Series;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\EpisodeLang;
use Api\Model\Series as SeriesModel;
use Api\Model\SerieLang;
use Api\Model\TheTvdb\Client;
use Api\Model\WatchedEpisode;
use Api\Model\Watchlist;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/series/{tvdbId}', methods: ['GET'], name: 'api.series.detail', requirements: ['tvdbId' => '\d+'])]
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

        $series = new SeriesModel();
        $info   = $series->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        $episodeRows = (new Episode())->syncForSeries($info['id_serie'], $tvdbId, $this->client);

        // regular numbered seasons only (season 0/specials excluded) among
        // this series' own already-synced episodes - see db.sql's
        // season_count comment for why this is preferred over TheTVDB's own
        // /extended `seasons` array
        $seasonCount = count(array_unique(array_filter(array_column($episodeRows, 'season_number'))));
        $series->updateSeasonCount($seasonCount);
        $info['season_count'] = $seasonCount;

        // $this->user is null for an anonymous request (default_token, no
        // real user logged in) - series detail itself is public, only the
        // per-user watched/watchlist flags need a real user
        $watchedIds = $this->user !== null
            ? (new WatchedEpisode())->watchedEpisodeIds($this->user->getID(), $info['id_serie'])
            : [];

        // Config::getLanguage() is already resolved per-request (Accept-
        // Language header for this sub-project, since 'api' isn't {lang}-
        // prefixed - see Core\Utils\Language::initLanguage()) - only that
        // one language's translation is fetched/returned, not every
        // language this app supports
        $culture = $this->config->getLanguage();

        // fall back to TheTVDB's own base record (default_name/
        // default_overview, normally the show's original-language text)
        // when the app's current language has no translation, rather than
        // showing a blank title/overview
        $translation      = (new SerieLang())->syncForLanguage($info['id_serie'], $tvdbId, $culture, $this->client);
        $info['name']     = $translation['name'] ?: $info['default_name'];
        $info['overview'] = $translation['overview'] ?: $info['default_overview'];

        // same default_name/default_overview fallback as the series itself
        // above, per episode
        $episodeTranslations = (new EpisodeLang())->syncForSerieAndLanguage($info['id_serie'], $tvdbId, $culture, $this->client);
        foreach ($episodeRows as &$episode) {
            $episode['watched']  = in_array($episode['id_episode'], $watchedIds, true);
            $episodeTranslation  = $episodeTranslations[$episode['id_episode']] ?? array('name' => null, 'overview' => null);
            $episode['name']     = $episodeTranslation['name'] ?: $episode['default_name'];
            $episode['overview'] = $episodeTranslation['overview'] ?: $episode['default_overview'];
        }
        unset($episode);

        $this->assign('series', $info);
        $this->assign('episodes', $episodeRows);
        $this->assign(
            'in_watchlist',
            $this->user !== null && (new Watchlist())->has($this->user->getID(), $info['id_serie'])
        );
    }

}

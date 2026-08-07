<?php

namespace Api\Controller\Series;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\EpisodeLang;
use Api\Model\Series as SeriesModel;
use Api\Model\SerieFavorite;
use Api\Model\SerieGenre;
use Api\Model\SerieLang;
use Api\Model\SerieTrailer;
use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
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

        // regular numbered seasons only (specials excluded) - see db.sql's season_count
        // comment for why this beats TheTVDB's own /extended `seasons` array
        $seasonCount = count(array_unique(array_filter(array_column($episodeRows, 'season_number'))));
        $series->updateSeasonCount($seasonCount);
        $info['season_count'] = $seasonCount;

        // $this->user is null for an anonymous request - series detail is public, only the
        // per-user watched/watchlist flags need a real user
        $watchedEpisode = new WatchedEpisode();
        $watchedIds     = $this->user !== null
            ? $watchedEpisode->watchedEpisodeIds($this->user->getID(), $info['id_serie'])
            : [];
        $watchCounts    = $this->user !== null
            ? $watchedEpisode->watchCounts($this->user->getID(), $info['id_serie'])
            : [];

        // Config::getLanguage() resolves via the Accept-Language header since 'api' isn't
        // {lang}-prefixed - see Core\Utils\Language::initLanguage(). Only that language is fetched
        $culture = $this->config->getLanguage();

        // fall back to TheTVDB's base record (default_name/default_overview, usually
        // original-language text) when there's no translation, rather than a blank title/overview
        $translation      = (new SerieLang())->syncForLanguage($info['id_serie'], $tvdbId, $culture, $this->client);
        $info['name']     = $translation['name'] ?: $info['default_name'];
        $info['overview'] = $translation['overview'] ?: $info['default_overview'];

        // no per-language text to fall back on - see SerieGenre's docblock. Genre labels
        // vary by culture client-side (l10n by slug), not here
        $info['genres'] = (new SerieGenre())->forSerie($info['id_serie']);

        $info['trailer'] = (new SerieTrailer())->bestForLanguage(
            $info['id_serie'],
            Languages::tvdbCodeForCulture($culture),
        );

        // same default_name/default_overview fallback as the series above, per episode
        $episodeTranslations = (new EpisodeLang())->syncForSerieAndLanguage($info['id_serie'], $tvdbId, $culture, $this->client);
        foreach ($episodeRows as &$episode) {
            $episode['watched']     = in_array($episode['id_episode'], $watchedIds, true);
            $episode['watch_count'] = $watchCounts[$episode['id_episode']] ?? 0;
            $episodeTranslation  = $episodeTranslations[$episode['id_episode']] ?? array('name' => null, 'overview' => null);
            $episode['name']     = $episodeTranslation['name'] ?: $episode['default_name'];
            $episode['overview'] = $episodeTranslation['overview'] ?: $episode['default_overview'];
        }
        unset($episode);

        $flags = $this->user !== null
            ? (new Watchlist())->getFlags($this->user->getID(), $info['id_serie'])
            : array('inWatchlist' => false, 'archived' => false, 'removed' => false);

        // independent of the watchlist flags above - see SerieFavorite's own docblock
        $isFavorite = $this->user !== null
            ? (new SerieFavorite())->has($this->user->getID(), $info['id_serie'])
            : false;

        $this->assign('series', $info);
        $this->assign('episodes', $episodeRows);
        $this->assign('in_watchlist', $flags['inWatchlist']);
        $this->assign('archived', $flags['archived']);
        $this->assign('removed', $flags['removed']);
        $this->assign('is_favorite', $isFavorite);
    }

}

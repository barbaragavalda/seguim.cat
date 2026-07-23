<?php

namespace Api\Controller\Series;

use Api\Controller\Controller;
use Api\Model\Episode;
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

        $watchedIds = (new WatchedEpisode())->watchedEpisodeIds($this->user->getID(), $info['id_serie']);
        foreach ($episodeRows as &$episode) {
            $episode['watched'] = in_array($episode['id_episode'], $watchedIds, true);
        }
        unset($episode);

        $translations = (new SerieLang())->syncForSerie($info['id_serie'], $tvdbId, $this->client);

        $this->assign('series', $info);
        $this->assign('translations', $translations);
        $this->assign('episodes', $episodeRows);
        $this->assign('in_watchlist', (new Watchlist())->has($this->user->getID(), $info['id_serie']));
    }

}

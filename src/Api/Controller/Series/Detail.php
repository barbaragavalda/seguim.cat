<?php

namespace Api\Controller\Series;

use Api\Controller\Controller;
use Api\Model\Episode;
use Api\Model\Series as SeriesModel;
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

        $episodeRows = (new Episode())->syncForSeries($info['id_series'], $tvdbId, $this->client);

        $watchedIds = (new WatchedEpisode())->watchedEpisodeIds($this->user->getID(), $info['id_series']);
        foreach ($episodeRows as &$episode) {
            $episode['watched'] = in_array($episode['id_episode'], $watchedIds, true);
        }
        unset($episode);

        $this->assign('series', $info);
        $this->assign('episodes', $episodeRows);
        $this->assign('in_watchlist', (new Watchlist())->has($this->user->getID(), $info['id_series']));
    }

}

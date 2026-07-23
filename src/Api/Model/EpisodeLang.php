<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Core\Model\Model;
use PDO;

class EpisodeLang extends Model
{

    private const int TTL_SECONDS = 86400; // 24h

    /**
     * fetches/refreshes every episode's translation for $culture in ONE
     * TheTVDB call if stale (see Client::getSeriesEpisodesTranslated() -
     * confirmed empirically to return all episodes at once, not one call
     * per episode), then returns them keyed by id_episode (this project's
     * own PK, not TheTVDB's). A missing key or ['name' => null, 'overview'
     * => null] both mean "no translation for this episode in this
     * language" - the caller doesn't need to tell the two apart
     */
    public function syncForSerieAndLanguage(int $idSerie, int $tvdbSerieId, string $culture, Client $client): array
    {
        $idAppacmanLang = Languages::idForCulture($culture);
        if ($idAppacmanLang === null) {
            return array();
        }

        if ($this->isStale($idSerie, $idAppacmanLang)) {
            $localEpisodeIds = $this->localEpisodeIdsByTvdbId($idSerie);
            $translated      = $client->getSeriesEpisodesTranslated($tvdbSerieId, Languages::tvdbCode($idAppacmanLang));

            foreach ($translated as $episode) {
                $tvdbEpisodeId = $episode['id'] ?? null;
                if ($tvdbEpisodeId === null || !isset($localEpisodeIds[$tvdbEpisodeId])) {
                    // no local episode row yet for this one - Episode::
                    // syncForSeries() runs first in the same request and
                    // should already cover every episode, but skip rather
                    // than fail if TheTVDB's two endpoints ever disagree
                    continue;
                }
                $this->upsert($localEpisodeIds[$tvdbEpisodeId], $idAppacmanLang, $episode);
            }
        }

        return $this->allForSerie($idSerie, $idAppacmanLang);
    }

    /**
     * @return array<int, int> tvdb_id -> id_episode for this serie's
     *                          already-synced local episode rows
     */
    private function localEpisodeIdsByTvdbId(int $idSerie): array
    {
        $sql    = '
            SELECT id_episode, tvdb_id
            FROM episode
            WHERE id_serie = :id_serie
        ';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        $map = array();
        foreach ($rows as $row) {
            $map[$row['tvdb_id']] = $row['id_episode'];
        }
        return $map;
    }

    private function isStale(int $idSerie, int $idAppacmanLang): bool
    {
        $sql      = '
            SELECT MAX(el.synced_at) AS synced_at
            FROM episode_lang el
            INNER JOIN episode e ON e.id_episode = el.id_episode
            WHERE e.id_serie = :id_serie AND el.id_appacman_lang = :id_appacman_lang
        ';
        $params   = array(
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $result   = $this->mysql->query($sql, $params);
        $syncedAt = $result[0]['synced_at'] ?? null;
        if (empty($syncedAt)) {
            return true;
        }
        return strtotime($syncedAt) <= (time() - self::TTL_SECONDS);
    }

    /**
     * @return array<int, array{name: ?string, overview: ?string}> keyed by
     *                                                              id_episode
     */
    private function allForSerie(int $idSerie, int $idAppacmanLang): array
    {
        $sql    = '
            SELECT e.id_episode, el.name, el.overview
            FROM episode e
            LEFT JOIN episode_lang el ON el.id_episode = e.id_episode AND el.id_appacman_lang = :id_appacman_lang
            WHERE e.id_serie = :id_serie
        ';
        $params = array(
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        $keyed = array();
        foreach ($rows as $row) {
            $keyed[$row['id_episode']] = array('name' => $row['name'], 'overview' => $row['overview']);
        }
        return $keyed;
    }

    private function upsert(int $idEpisode, int $idAppacmanLang, array $translation): void
    {
        $sql    = '
            INSERT INTO episode_lang (id_episode, id_appacman_lang, name, overview, synced_at)
            VALUES (:id_episode, :id_appacman_lang, :name, :overview, NOW())
            ON DUPLICATE KEY UPDATE
                name = :name_upd, overview = :overview_upd, synced_at = NOW()
        ';
        $name     = $translation['name'] ?? null;
        $overview = $translation['overview'] ?? null;

        $params = array(
            'id_episode'       => array('value' => $idEpisode, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
            'name'             => array('value' => $name, 'type' => PDO::PARAM_STR),
            'name_upd'         => array('value' => $name, 'type' => PDO::PARAM_STR),
            'overview'         => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'overview_upd'     => array('value' => $overview, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

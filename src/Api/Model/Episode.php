<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

class Episode extends Model
{

    private const int TTL_SECONDS = 86400; // 24h

    protected array $info = array();

    public function getInfo(): array
    {
        return $this->info;
    }

    public function loadWithTvdbId(int $tvdbId): bool|int
    {
        $sql     = '
            SELECT *
            FROM episode
            WHERE tvdb_id = :tvdb_id
        ';
        $params  = array(
            'tvdb_id' => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
        );
        $episode = $this->mysql->query($sql, $params);
        return $this->load($episode);
    }

    /**
     * returns the local mirror rows for the series' episodes, fetching/
     * upserting them from TheTVDB first if missing or stale
     */
    public function syncForSeries(int $idSerie, int $tvdbSeriesId, Client $client): array
    {
        if ($this->isStale($idSerie)) {
            $episodes = $client->getSeriesEpisodes($tvdbSeriesId);
            foreach ($episodes as $episode) {
                $this->upsert($idSerie, $episode);
            }
        }

        $sql    = '
            SELECT *
            FROM episode
            WHERE id_serie = :id_serie
            ORDER BY season_number, episode_number
        ';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    private function isStale(int $idSerie): bool
    {
        $sql      = '
            SELECT MAX(synced_at) AS synced_at
            FROM episode
            WHERE id_serie = :id_serie
        ';
        $params   = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $result   = $this->mysql->query($sql, $params);
        $syncedAt = $result[0]['synced_at'] ?? null;
        if (empty($syncedAt)) {
            return true;
        }
        return strtotime($syncedAt) <= (time() - self::TTL_SECONDS);
    }

    private function upsert(int $idSerie, array $data): void
    {
        $sql           = '
            INSERT INTO episode (
                id_serie, tvdb_id, season_number, episode_number, name, overview, aired, image, runtime, synced_at
            )
            VALUES (
                :id_serie, :tvdb_id, :season_number, :episode_number, :name, :overview, :aired, :image, :runtime, NOW()
            )
            ON DUPLICATE KEY UPDATE
                season_number = :season_number_upd, episode_number = :episode_number_upd,
                name = :name_upd, overview = :overview_upd, aired = :aired_upd,
                image = :image_upd, runtime = :runtime_upd, synced_at = NOW()
        ';
        $tvdbId        = $data['id'] ?? 0;
        $seasonNumber  = $data['seasonNumber'] ?? 0;
        $episodeNumber = $data['number'] ?? 0;
        $name          = $data['name'] ?? null;
        $overview      = $data['overview'] ?? null;
        $aired         = !empty($data['aired']) ? $data['aired'] : null;
        $image         = $data['image'] ?? null;
        $runtime       = $data['runtime'] ?? null;

        $params = array(
            'id_serie'           => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'tvdb_id'            => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
            'season_number'      => array('value' => $seasonNumber, 'type' => PDO::PARAM_INT),
            'season_number_upd'  => array('value' => $seasonNumber, 'type' => PDO::PARAM_INT),
            'episode_number'     => array('value' => $episodeNumber, 'type' => PDO::PARAM_INT),
            'episode_number_upd' => array('value' => $episodeNumber, 'type' => PDO::PARAM_INT),
            'name'               => array('value' => $name, 'type' => PDO::PARAM_STR),
            'name_upd'           => array('value' => $name, 'type' => PDO::PARAM_STR),
            'overview'           => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'overview_upd'       => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'aired'              => array('value' => $aired, 'type' => PDO::PARAM_STR),
            'aired_upd'          => array('value' => $aired, 'type' => PDO::PARAM_STR),
            'image'              => array('value' => $image, 'type' => PDO::PARAM_STR),
            'image_upd'          => array('value' => $image, 'type' => PDO::PARAM_STR),
            'runtime'            => array('value' => $runtime, 'type' => PDO::PARAM_INT),
            'runtime_upd'        => array('value' => $runtime, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function load(array $episode): bool|int
    {
        if (count($episode)) {
            $this->info = $episode[0];
            $this->id   = $this->info['id_episode'];
            return $this->id;
        }
        $this->info = array();
        return false;
    }

}

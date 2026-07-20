<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

class Series extends Model
{

    private const int TTL_SECONDS = 86400; // 24h

    protected array $info = array();

    public function getInfo(): array
    {
        return $this->info;
    }

    public function loadWithTvdbId(int $tvdbId): bool|int
    {
        $sql    = '
            SELECT *
            FROM series
            WHERE tvdb_id = :tvdb_id
        ';
        $params = array(
            'tvdb_id' => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
        );
        $series = $this->mysql->query($sql, $params);
        return $this->load($series);
    }

    /**
     * returns the local mirror row for $tvdbId, fetching/upserting it from
     * TheTVDB first if it's missing or stale - this is the "on-demand lazy
     * mirroring" behavior, no full-catalog sync involved
     */
    public function sync(int $tvdbId, Client $client): array
    {
        $found = $this->loadWithTvdbId($tvdbId);
        if ($found && !$this->isStale()) {
            return $this->info;
        }

        $data = $client->getSeries($tvdbId);
        if (empty($data)) {
            // TheTVDB unreachable/bad id - fall back to whatever's already
            // local rather than failing the whole request
            return $this->info;
        }

        $this->upsert($tvdbId, $data);
        $this->loadWithTvdbId($tvdbId);
        return $this->info;
    }

    private function isStale(): bool
    {
        if (empty($this->info['synced_at'])) {
            return true;
        }
        return strtotime($this->info['synced_at']) <= (time() - self::TTL_SECONDS);
    }

    private function upsert(int $tvdbId, array $data): void
    {
        $sql    = '
            INSERT INTO series (tvdb_id, name, overview, image, year, status, slug, synced_at)
            VALUES (:tvdb_id, :name, :overview, :image, :year, :status, :slug, NOW())
            ON DUPLICATE KEY UPDATE
                name = :name_upd, overview = :overview_upd, image = :image_upd,
                year = :year_upd, status = :status_upd, slug = :slug_upd, synced_at = NOW()
        ';
        $name     = $data['name'] ?? '';
        $overview = $data['overview'] ?? null;
        $image    = $data['image'] ?? null;
        $year     = $data['year'] ?? null;
        // SeriesBaseRecord's status is an object ({id, name, recordType,
        // keepUpdated}), not a plain string like on a /search SearchResult
        $status   = $data['status']['name'] ?? null;
        $slug     = $data['slug'] ?? null;

        $params = array(
            'tvdb_id'      => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
            'name'         => array('value' => $name, 'type' => PDO::PARAM_STR),
            'name_upd'     => array('value' => $name, 'type' => PDO::PARAM_STR),
            'overview'     => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'overview_upd' => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'image'        => array('value' => $image, 'type' => PDO::PARAM_STR),
            'image_upd'    => array('value' => $image, 'type' => PDO::PARAM_STR),
            'year'         => array('value' => $year, 'type' => PDO::PARAM_STR),
            'year_upd'     => array('value' => $year, 'type' => PDO::PARAM_STR),
            'status'       => array('value' => $status, 'type' => PDO::PARAM_STR),
            'status_upd'   => array('value' => $status, 'type' => PDO::PARAM_STR),
            'slug'         => array('value' => $slug, 'type' => PDO::PARAM_STR),
            'slug_upd'     => array('value' => $slug, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    private function load(array $series): bool|int
    {
        if (count($series)) {
            $this->info = $series[0];
            $this->id   = $this->info['id_series'];
            return $this->id;
        }
        $this->info = array();
        return false;
    }

}

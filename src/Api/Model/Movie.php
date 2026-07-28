<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

class Movie extends Model
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
            FROM movie
            WHERE tvdb_id = :tvdb_id
        ';
        $params = array(
            'tvdb_id' => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
        );
        $movie = $this->mysql->query($sql, $params);
        return $this->load($movie);
    }

    /**
     * same on-demand lazy-mirroring shape as Series::sync() - fetches/
     * upserts from TheTVDB only when missing or past the 24h TTL
     */
    public function sync(int $tvdbId, Client $client): array
    {
        $found = $this->loadWithTvdbId($tvdbId);
        if ($found && !$this->isStale()) {
            return $this->info;
        }

        $data = $client->getMovie($tvdbId);
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
        $sql   = '
            INSERT INTO movie (tvdb_id, default_name, default_overview, image, background, year, runtime, status, slug, synced_at)
            VALUES (:tvdb_id, :default_name, :default_overview, :image, :background, :year, :runtime, :status, :slug, NOW())
            ON DUPLICATE KEY UPDATE
                default_name = :default_name_upd, default_overview = :default_overview_upd,
                image = :image_upd, background = :background_upd, year = :year_upd,
                runtime = :runtime_upd, status = :status_upd, slug = :slug_upd, synced_at = NOW()
        ';
        // TheTVDB's own base/extended record name/overview - normally the
        // movie's original-language text, used as a fallback when
        // movie_lang has no translation for the app's current language (see
        // Movie\Detail controller)
        $defaultName     = $data['name'] ?? null;
        $defaultOverview = $data['overview'] ?? null;
        $image      = $data['image'] ?? null;
        $background = $data['background'] ?? null;
        $year       = $data['year'] ?? null;
        $runtime    = $data['runtime'] ?? null;
        // MovieExtendedRecord's status is an object ({id, name, recordType,
        // keepUpdated}), same shape as a series' own status
        $status = $data['status']['name'] ?? null;
        $slug   = $data['slug'] ?? null;

        $params = array(
            'tvdb_id'              => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
            'default_name'         => array('value' => $defaultName, 'type' => PDO::PARAM_STR),
            'default_name_upd'     => array('value' => $defaultName, 'type' => PDO::PARAM_STR),
            'default_overview'     => array('value' => $defaultOverview, 'type' => PDO::PARAM_STR),
            'default_overview_upd' => array('value' => $defaultOverview, 'type' => PDO::PARAM_STR),
            'image'                => array('value' => $image, 'type' => PDO::PARAM_STR),
            'image_upd'            => array('value' => $image, 'type' => PDO::PARAM_STR),
            'background'           => array('value' => $background, 'type' => PDO::PARAM_STR),
            'background_upd'       => array('value' => $background, 'type' => PDO::PARAM_STR),
            'year'                 => array('value' => $year, 'type' => PDO::PARAM_STR),
            'year_upd'             => array('value' => $year, 'type' => PDO::PARAM_STR),
            'runtime'              => array('value' => $runtime, 'type' => PDO::PARAM_INT),
            'runtime_upd'          => array('value' => $runtime, 'type' => PDO::PARAM_INT),
            'status'               => array('value' => $status, 'type' => PDO::PARAM_STR),
            'status_upd'           => array('value' => $status, 'type' => PDO::PARAM_STR),
            'slug'                 => array('value' => $slug, 'type' => PDO::PARAM_STR),
            'slug_upd'             => array('value' => $slug, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    private function load(array $movie): bool|int
    {
        if (count($movie)) {
            $this->info = $movie[0];
            $this->id   = $this->info['id_movie'];
            return $this->id;
        }
        $this->info = array();
        return false;
    }

}

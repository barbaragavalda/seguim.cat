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
            FROM serie
            WHERE tvdb_id = :tvdb_id
        ';
        $params = array(
            'tvdb_id' => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
        );
        $series = $this->mysql->query($sql, $params);
        return $this->load($series);
    }

    /**
     * Which of $tvdbIds are already synced locally, and what id_serie each
     * maps to. A tvdb_id missing from the result simply isn't synced yet.
     *
     * @param int[] $tvdbIds
     * @return array<int, int> tvdb_id => id_serie
     */
    public function idsForTvdbIds(array $tvdbIds): array
    {
        if (empty($tvdbIds)) {
            return array();
        }

        $params       = array();
        $placeholders = array();
        foreach (array_values($tvdbIds) as $index => $tvdbId) {
            $key            = 'tvdb_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key]   = array('value' => $tvdbId, 'type' => PDO::PARAM_INT);
        }

        $sql  = '
            SELECT id_serie, tvdb_id
            FROM serie
            WHERE tvdb_id IN (' . implode(',', $placeholders) . ')
        ';
        $rows = $this->mysql->query($sql, $params);

        $result = array();
        foreach ($rows as $row) {
            $result[(int) $row['tvdb_id']] = (int) $row['id_serie'];
        }
        return $result;
    }

    /** Lazy-mirrors $tvdbId from TheTVDB if missing/stale - no full-catalog sync. */
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

        // a separate TheTVDB endpoint - piggybacks on this same sync()/isStale() cycle
        $data['background'] = $client->getSeriesBackground($tvdbId);

        $this->upsert($tvdbId, $data);
        $this->loadWithTvdbId($tvdbId);

        // full replace each cycle rather than incremental upserts
        (new SerieGenre())->syncForSerie((int) $this->info['id_serie'], $data['genres'] ?? array());
        (new SerieTrailer())->syncForSerie((int) $this->info['id_serie'], $data['trailers'] ?? array());

        return $this->info;
    }

    /**
     * Called once the caller has synced episodes and counted distinct
     * regular seasons - kept separate from upsert() since that count
     * depends on episode data this model doesn't itself fetch.
     */
    public function updateSeasonCount(int $seasonCount): void
    {
        $sql    = '
            UPDATE serie
            SET season_count = :season_count
            WHERE id_serie = :id_serie
        ';
        $params = array(
            'season_count' => array('value' => $seasonCount, 'type' => PDO::PARAM_INT),
            'id_serie'     => array('value' => $this->id, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
        $this->info['season_count'] = $seasonCount;
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
            INSERT INTO serie (tvdb_id, default_name, default_overview, image, background, year_start, year_end, status, slug, average_runtime, synced_at)
            VALUES (:tvdb_id, :default_name, :default_overview, :image, :background, :year_start, :year_end, :status, :slug, :average_runtime, NOW())
            ON DUPLICATE KEY UPDATE
                default_name = :default_name_upd, default_overview = :default_overview_upd,
                image = :image_upd, background = :background_upd, year_start = :year_start_upd, year_end = :year_end_upd,
                status = :status_upd, slug = :slug_upd,
                average_runtime = :average_runtime_upd, synced_at = NOW()
        ';
        // fallback for when serie_lang has no translation for the current language
        $defaultName     = $data['name'] ?? null;
        $defaultOverview = $data['overview'] ?? null;
        $image      = $data['image'] ?? null;
        $background = $data['background'] ?? null;
        // only the year is stored; year_end reflects the latest aired
        // episode's year for an ongoing show, not necessarily a real end date
        $yearStart = !empty($data['firstAired']) ? substr($data['firstAired'], 0, 4) : null;
        $yearEnd   = !empty($data['lastAired']) ? substr($data['lastAired'], 0, 4) : null;
        // status is an object ({id, name, ...}), not a plain string like /search's SearchResult
        $status         = $data['status']['name'] ?? null;
        $slug           = $data['slug'] ?? null;
        $averageRuntime = $data['averageRuntime'] ?? null;

        $params = array(
            'tvdb_id'                 => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
            'default_name'            => array('value' => $defaultName, 'type' => PDO::PARAM_STR),
            'default_name_upd'        => array('value' => $defaultName, 'type' => PDO::PARAM_STR),
            'default_overview'        => array('value' => $defaultOverview, 'type' => PDO::PARAM_STR),
            'default_overview_upd'    => array('value' => $defaultOverview, 'type' => PDO::PARAM_STR),
            'image'               => array('value' => $image, 'type' => PDO::PARAM_STR),
            'image_upd'           => array('value' => $image, 'type' => PDO::PARAM_STR),
            'background'          => array('value' => $background, 'type' => PDO::PARAM_STR),
            'background_upd'      => array('value' => $background, 'type' => PDO::PARAM_STR),
            'year_start'          => array('value' => $yearStart, 'type' => PDO::PARAM_STR),
            'year_start_upd'      => array('value' => $yearStart, 'type' => PDO::PARAM_STR),
            'year_end'            => array('value' => $yearEnd, 'type' => PDO::PARAM_STR),
            'year_end_upd'        => array('value' => $yearEnd, 'type' => PDO::PARAM_STR),
            'status'              => array('value' => $status, 'type' => PDO::PARAM_STR),
            'status_upd'          => array('value' => $status, 'type' => PDO::PARAM_STR),
            'slug'                => array('value' => $slug, 'type' => PDO::PARAM_STR),
            'slug_upd'            => array('value' => $slug, 'type' => PDO::PARAM_STR),
            'average_runtime'     => array('value' => $averageRuntime, 'type' => PDO::PARAM_INT),
            'average_runtime_upd' => array('value' => $averageRuntime, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function load(array $series): bool|int
    {
        if (count($series)) {
            $this->info = $series[0];
            $this->id   = $this->info['id_serie'];
            return $this->id;
        }
        $this->info = array();
        return false;
    }

}

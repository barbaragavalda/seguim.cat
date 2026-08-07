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
     * upserting them from TheTVDB first if missing or stale. Also
     * reconciles removals: TheTVDB corrects itself over time (a duplicate
     * episode gets merged, a season gets renumbered, ...), and this app
     * should follow suit rather than keeping "ghost" episodes around
     * forever - see removeStale()
     */
    public function syncForSeries(int $idSerie, int $tvdbSeriesId, Client $client): array
    {
        if ($this->isStale($idSerie)) {
            $episodes = $client->getSeriesEpisodes($tvdbSeriesId);
            // an empty response is indistinguishable from "TheTVDB is
            // unreachable right now" (see Client::request()) - never treat
            // that as "this series really has zero episodes now" and wipe
            // everything; only reconcile against a genuine, non-empty list
            if (!empty($episodes)) {
                foreach ($episodes as $episode) {
                    $this->upsert($idSerie, $episode);
                }
                $this->removeStale($idSerie, array_column($episodes, 'id'));
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

    /**
     * deletes every local episode row for $idSerie whose tvdb_id isn't in
     * $currentTvdbIds (the fresh, complete list TheTVDB just returned),
     * along with any user's watch history for them - there's no undo, but
     * an episode TheTVDB no longer lists isn't one this app should keep
     * pretending exists
     *
     * @param int[] $currentTvdbIds
     */
    private function removeStale(int $idSerie, array $currentTvdbIds): void
    {
        $params       = array('id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT));
        $placeholders = array();
        foreach (array_values($currentTvdbIds) as $index => $tvdbId) {
            $key            = 'tvdb_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key]   = array('value' => $tvdbId, 'type' => PDO::PARAM_INT);
        }

        $sql  = '
            SELECT id_episode
            FROM episode
            WHERE id_serie = :id_serie AND tvdb_id NOT IN (' . implode(',', $placeholders) . ')
        ';
        $rows = $this->mysql->query($sql, $params);
        if (empty($rows)) {
            return;
        }

        $staleIds = array_column($rows, 'id_episode');
        (new WatchedEpisode())->removeForEpisodes($staleIds);

        $params       = array();
        $placeholders = array();
        foreach (array_values($staleIds) as $index => $idEpisode) {
            $key            = 'id_episode_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key]   = array('value' => $idEpisode, 'type' => PDO::PARAM_INT);
        }
        $sql = '
            DELETE FROM episode
            WHERE id_episode IN (' . implode(',', $placeholders) . ')
        ';
        $this->mysql->query($sql, $params);
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
        $sql             = '
            INSERT INTO episode (
                id_serie, tvdb_id, season_number, episode_number, default_name, default_overview, aired, image, runtime, synced_at
            )
            VALUES (
                :id_serie, :tvdb_id, :season_number, :episode_number, :default_name, :default_overview, :aired, :image, :runtime, NOW()
            )
            ON DUPLICATE KEY UPDATE
                season_number = :season_number_upd, episode_number = :episode_number_upd,
                default_name = :default_name_upd, default_overview = :default_overview_upd,
                aired = :aired_upd, image = :image_upd, runtime = :runtime_upd, synced_at = NOW()
        ';
        $tvdbId          = $data['id'] ?? 0;
        $seasonNumber    = $data['seasonNumber'] ?? 0;
        $episodeNumber   = $data['number'] ?? 0;
        // TheTVDB's own base record name/overview - fallback for when
        // episode_lang has no translation for the app's current language
        $defaultName     = $data['name'] ?? null;
        $defaultOverview = $data['overview'] ?? null;
        $aired           = !empty($data['aired']) ? $data['aired'] : null;
        $image           = $data['image'] ?? null;
        $runtime         = $data['runtime'] ?? null;

        $params = array(
            'id_serie'               => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'tvdb_id'                => array('value' => $tvdbId, 'type' => PDO::PARAM_INT),
            'season_number'          => array('value' => $seasonNumber, 'type' => PDO::PARAM_INT),
            'season_number_upd'      => array('value' => $seasonNumber, 'type' => PDO::PARAM_INT),
            'episode_number'         => array('value' => $episodeNumber, 'type' => PDO::PARAM_INT),
            'episode_number_upd'     => array('value' => $episodeNumber, 'type' => PDO::PARAM_INT),
            'default_name'           => array('value' => $defaultName, 'type' => PDO::PARAM_STR),
            'default_name_upd'       => array('value' => $defaultName, 'type' => PDO::PARAM_STR),
            'default_overview'       => array('value' => $defaultOverview, 'type' => PDO::PARAM_STR),
            'default_overview_upd'   => array('value' => $defaultOverview, 'type' => PDO::PARAM_STR),
            'aired'                  => array('value' => $aired, 'type' => PDO::PARAM_STR),
            'aired_upd'              => array('value' => $aired, 'type' => PDO::PARAM_STR),
            'image'                  => array('value' => $image, 'type' => PDO::PARAM_STR),
            'image_upd'              => array('value' => $image, 'type' => PDO::PARAM_STR),
            'runtime'                => array('value' => $runtime, 'type' => PDO::PARAM_INT),
            'runtime_upd'            => array('value' => $runtime, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * how many of each series' aired regular episodes $idUser has watched -
     * batched across every id in $idSeries in one query rather than one per
     * series, since Search/Lists results return many at once. Same
     * "regular episodes only, already aired" definition as Watchlist::
     * remainingEpisodes(). A series absent from the returned array has no
     * aired regular episodes synced locally yet - that's "no data to show a
     * progress bar for", not "0 watched"; the caller should treat it that
     * way rather than rendering an empty bar.
     *
     * @param int[] $idSeries
     * @return array<int, array{watched: int, total: int}> keyed by id_serie
     */
    public function watchProgressForSeries(int $idUser, array $idSeries): array
    {
        if (empty($idSeries)) {
            return array();
        }

        $params       = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $placeholders = array();
        foreach (array_values($idSeries) as $index => $idSerie) {
            $key            = 'id_serie_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key]   = array('value' => $idSerie, 'type' => PDO::PARAM_INT);
        }

        $sql = '
            SELECT e.id_serie,
                   COUNT(DISTINCT e.id_episode) AS total,
                   COUNT(DISTINCT uew.id_episode) AS watched
            FROM episode e
            LEFT JOIN user_episode_watched uew ON uew.id_episode = e.id_episode AND uew.id_user = :id_user
            WHERE e.id_serie IN (' . implode(',', $placeholders) . ')
              AND e.season_number > 0
              AND e.aired IS NOT NULL AND e.aired <= CURDATE()
            GROUP BY e.id_serie
        ';
        $rows = $this->mysql->query($sql, $params);

        $result = array();
        foreach ($rows as $row) {
            $result[(int) $row['id_serie']] = array(
                'watched' => (int) $row['watched'],
                'total'   => (int) $row['total'],
            );
        }
        return $result;
    }

    /**
     * Attaches watched_episodes/total_episodes to every row that already
     * carries a real id_serie - used by Lists\Show and Favorites\Series,
     * which both list already-synced series (unlike Search\Search, which
     * still needs its own tvdb_id -> id_serie lookup first).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function attachWatchProgress(array $rows, int $idUser): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $idSeries = array_map(static fn(array $r): int => (int) $r['id_serie'], $rows);
        $progress = $this->watchProgressForSeries($idUser, $idSeries);

        foreach ($rows as &$row) {
            $idSerie = (int) $row['id_serie'];
            if (isset($progress[$idSerie])) {
                $row['watched_episodes'] = $progress[$idSerie]['watched'];
                $row['total_episodes']   = $progress[$idSerie]['total'];
            }
        }
        unset($row);

        return $rows;
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

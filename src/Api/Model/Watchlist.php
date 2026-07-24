<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class Watchlist extends Model
{

    public function add(int $idUser, int $idSerie): void
    {
        $sql    = '
            INSERT IGNORE INTO user_watchlist (id_user, id_serie)
            VALUES (:id_user, :id_serie)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function remove(int $idUser, int $idSerie): void
    {
        $sql    = '
            DELETE FROM user_watchlist
            WHERE id_user = :id_user AND id_serie = :id_serie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_watchlist
            WHERE id_user = :id_user
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUser, int $idSerie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_watchlist
            WHERE id_user = :id_user AND id_serie = :id_serie
            LIMIT 1
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /**
     * $idAppacmanLang is the current request's already-resolved language
     * (Api\Model\TheTvdb\Languages::idForCulture(Config::getLanguage())) - a
     * LEFT JOIN, not INNER, so a series still shows up even if that language's
     * translation hasn't been synced yet (sl.name/sl.overview just come back
     * null, same as Series/Detail's fallback: sl.name ?: s.default_name)
     */
    public function listForUser(int $idUser, int $idAppacmanLang): array
    {
        $sql    = '
            SELECT s.*, sl.name, sl.overview
            FROM user_watchlist w
            INNER JOIN serie s ON s.id_serie = w.id_serie
            LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
            WHERE w.id_user = :id_user
            ORDER BY w.created DESC
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        foreach ($rows as &$row) {
            $row['name']     = $row['name'] ?: $row['default_name'];
            $row['overview'] = $row['overview'] ?: $row['default_overview'];

            // watchlist only ever shows the background/fanart, never the
            // poster - confirmed with the user, so `image` is overwritten
            // rather than kept alongside `background`
            $row['image'] = $row['background'];
            unset($row['background']);

            $remaining                  = $this->remainingEpisodes($idUser, (int) $row['id_serie']);
            $next                       = $remaining[0] ?? null;
            $row['next_episode']        = $next !== null
                ? sprintf('T%d - E%d', $next['season_number'], $next['episode_number'])
                : null;
            $row['remaining_episodes']  = count($remaining);
        }
        unset($row);

        return $rows;
    }

    /**
     * unwatched episodes of $idSerie, oldest first - season 0 (specials) is
     * always excluded, same convention Series/Detail already uses for
     * season_count: TheTVDB has no reliable field to tell a plot-relevant
     * special apart from a clip-show/recap one (checked empirically against
     * Lost and Euphoria - `airsBeforeSeason`/`airsBeforeEpisode`/`finaleType`
     * are set inconsistently on both kinds in both shows), so there's no
     * sound way to count only the specials that matter. Episodes not yet
     * aired are excluded too - nothing to "watch" yet.
     *
     * @return array<int, array{season_number: int, episode_number: int}>
     */
    private function remainingEpisodes(int $idUser, int $idSerie): array
    {
        $sql    = '
            SELECT e.season_number, e.episode_number
            FROM episode e
            LEFT JOIN user_episode_watched w ON w.id_episode = e.id_episode AND w.id_user = :id_user
            WHERE e.id_serie = :id_serie
              AND e.season_number > 0
              AND e.aired IS NOT NULL AND e.aired <= CURDATE()
              AND w.id_episode IS NULL
            ORDER BY e.season_number ASC, e.episode_number ASC
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

}

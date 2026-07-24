<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class Watchlist extends Model
{

    private const int PAGE_SIZE = 20;

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
     * series with at least one watched episode, most-recently-watched first
     * (i.e. "continue watching") - $idAppacmanLang, pagination and the
     * name/overview/image/next_episode/remaining_episodes treatment are the
     * same as listNotStarted(), see that method's docblock
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listWatching(int $idUser, int $idAppacmanLang, int $page): array
    {
        $sql    = '
            SELECT s.*, MAX(sl.name) AS name, MAX(sl.overview) AS overview
            FROM user_watchlist w
            INNER JOIN serie s ON s.id_serie = w.id_serie
            LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
            INNER JOIN user_episode_watched uew ON uew.id_user = w.id_user
            INNER JOIN episode e ON e.id_episode = uew.id_episode AND e.id_serie = s.id_serie
            WHERE w.id_user = :id_user
            GROUP BY s.id_serie
            ORDER BY MAX(uew.watched_at) DESC
            LIMIT :limit OFFSET :offset
        ';
        $rows   = $this->mysql->query($sql, $this->pageParams($idUser, $idAppacmanLang, $page));

        return $this->finalizePage($rows, $idUser);
    }

    /**
     * series with zero watched episodes, most-recently-added-to-the-
     * watchlist first. $idAppacmanLang is the current request's already-
     * resolved language (Api\Model\TheTvdb\Languages::idForCulture(Config::
     * getLanguage())) - a LEFT JOIN, not INNER, so a series still shows up
     * even if that language's translation hasn't been synced yet (sl.name/
     * sl.overview just come back null, same as Series/Detail's fallback:
     * sl.name ?: s.default_name). Pagination fetches one extra row
     * (PAGE_SIZE + 1, see pageParams()) purely to detect hasMore without a
     * separate COUNT(*) query - finalizePage() trims it back off.
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listNotStarted(int $idUser, int $idAppacmanLang, int $page): array
    {
        $sql    = '
            SELECT s.*, sl.name, sl.overview
            FROM user_watchlist w
            INNER JOIN serie s ON s.id_serie = w.id_serie
            LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
            WHERE w.id_user = :id_user
              AND NOT EXISTS (
                  SELECT 1
                  FROM user_episode_watched uew
                  INNER JOIN episode e ON e.id_episode = uew.id_episode
                  WHERE uew.id_user = w.id_user AND e.id_serie = s.id_serie
              )
            ORDER BY w.created DESC
            LIMIT :limit OFFSET :offset
        ';
        $rows   = $this->mysql->query($sql, $this->pageParams($idUser, $idAppacmanLang, $page));

        return $this->finalizePage($rows, $idUser);
    }

    private function pageParams(int $idUser, int $idAppacmanLang, int $page): array
    {
        return array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
            'limit'            => array('value' => self::PAGE_SIZE + 1, 'type' => PDO::PARAM_INT),
            'offset'           => array('value' => max(0, $page) * self::PAGE_SIZE, 'type' => PDO::PARAM_INT),
        );
    }

    /**
     * @return array{results: array, hasMore: bool}
     */
    private function finalizePage(array $rows, int $idUser): array
    {
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);

        foreach ($rows as &$row) {
            $row['name']     = $row['name'] ?: $row['default_name'];
            $row['overview'] = $row['overview'] ?: $row['default_overview'];

            // watchlist only ever shows the background/fanart, never the
            // poster - confirmed with the user, so `image` is overwritten
            // rather than kept alongside `background`
            $row['image'] = $row['background'];
            unset($row['background']);

            $remaining                 = $this->remainingEpisodes($idUser, (int) $row['id_serie']);
            $next                      = $remaining[0] ?? null;
            $row['next_episode']       = $next !== null
                ? sprintf('T%d - E%d', $next['season_number'], $next['episode_number'])
                : null;
            $row['remaining_episodes'] = count($remaining);
        }
        unset($row);

        return array('results' => $rows, 'hasMore' => $hasMore);
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

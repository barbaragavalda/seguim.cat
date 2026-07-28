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
            INSERT IGNORE INTO user_serie_watchlist (id_user, id_serie)
            VALUES (:id_user, :id_serie)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * used only by the TV Time importer (Api\Model\TvTimeImport\Processor) -
     * an upsert rather than add()'s INSERT IGNORE, so re-running an import
     * (or importing after the show was already added normally) still
     * updates the archived/removed flags rather than silently keeping
     * whatever was there before. $createdAt preserves TV Time's own follow
     * date (falls back to "now" when the export has none - see
     * Parser::parse()'s "removed" shows) so listNotStarted()'s "most
     * recently added" ordering stays meaningful for imported shows instead
     * of every one of them tying for the import's own timestamp
     */
    public function addFromImport(int $idUser, int $idSerie, bool $archived, bool $removed, ?string $createdAt = null): void
    {
        $sql    = '
            INSERT INTO user_serie_watchlist (id_user, id_serie, archived, removed, created)
            VALUES (:id_user, :id_serie, :archived, :removed, :created)
            ON DUPLICATE KEY UPDATE archived = :archived_upd, removed = :removed_upd
        ';
        $params = array(
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie'     => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'archived'     => array('value' => (int) $archived, 'type' => PDO::PARAM_INT),
            'archived_upd' => array('value' => (int) $archived, 'type' => PDO::PARAM_INT),
            'removed'      => array('value' => (int) $removed, 'type' => PDO::PARAM_INT),
            'removed_upd'  => array('value' => (int) $removed, 'type' => PDO::PARAM_INT),
            'created'      => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * user-driven archive/unarchive (Api\Controller\Watchlist\{Archive,
     * Unarchive}) - same `archived` flag the TV Time importer sets, just
     * togglable by hand now too. A no-op if $idSerie isn't actually in the
     * user's watchlist, same tolerant style as remove() below
     */
    public function setArchived(int $idUser, int $idSerie, bool $archived): void
    {
        $sql    = '
            UPDATE user_serie_watchlist
            SET archived = :archived
            WHERE id_user = :id_user AND id_serie = :id_serie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'archived' => array('value' => (int) $archived, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * user-driven mark-removed/restore (Api\Controller\Watchlist\
     * {MarkRemoved,Restore}) - unlike remove() below, this keeps the row
     * (and its watch history) and just hides it from both watchlist
     * listings, same `removed` flag the TV Time importer sets for a show
     * no longer followed there
     */
    public function setRemoved(int $idUser, int $idSerie, bool $removed): void
    {
        $sql    = '
            UPDATE user_serie_watchlist
            SET removed = :removed
            WHERE id_user = :id_user AND id_serie = :id_serie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'removed'  => array('value' => (int) $removed, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * hard delete - the row (and its archived/removed flags) is gone
     * entirely, unlike setRemoved()'s soft hide above. Watched-episode
     * history isn't touched (a separate table)
     */
    public function remove(int $idUser, int $idSerie): void
    {
        $sql    = '
            DELETE FROM user_serie_watchlist
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
            DELETE FROM user_serie_watchlist
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
            FROM user_serie_watchlist
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
     * used by Api\Controller\Series\Detail so the series detail screen can
     * show the archived/removed toggles in their current state, not just
     * the plain in-watchlist flag has() gives - a row not in the watchlist
     * at all comes back as every flag false, same as a fresh/never-added
     * series, rather than null/missing
     *
     * @return array{inWatchlist: bool, archived: bool, removed: bool}
     */
    public function getFlags(int $idUser, int $idSerie): array
    {
        $sql    = '
            SELECT archived, removed
            FROM user_serie_watchlist
            WHERE id_user = :id_user AND id_serie = :id_serie
            LIMIT 1
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        if (count($rows) === 0) {
            return array('inWatchlist' => false, 'archived' => false, 'removed' => false);
        }
        return array(
            'inWatchlist' => true,
            'archived'    => (bool) $rows[0]['archived'],
            'removed'     => (bool) $rows[0]['removed'],
        );
    }

    /**
     * series with at least one watched episode AND still something left to
     * watch, most-recently-watched first (i.e. "continue watching") - a
     * series the user has fully caught up on (remaining_episodes reaches 0)
     * drops out of this list; it reappears on its own once a new episode
     * airs, since remaining_episodes is computed fresh every call, not
     * stored. Not paginated, unlike listNotStarted(): a personal tracker's
     * in-progress list stays small by nature, so the extra page/hasMore
     * surface isn't worth it here. Archived/removed shows (only ever set by
     * the TV Time importer) are excluded here too, same as listNotStarted()
     * - the rows themselves aren't deleted, just hidden from both lists.
     * $idAppacmanLang and the name/overview/image/next_episode/
     * remaining_episodes treatment are the same as listNotStarted(), see
     * that method's docblock
     */
    public function listWatching(int $idUser, int $idAppacmanLang): array
    {
        $sql    = '
            SELECT s.*, MAX(sl.name) AS name, MAX(sl.overview) AS overview
            FROM user_serie_watchlist w
            INNER JOIN serie s ON s.id_serie = w.id_serie
            LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
            INNER JOIN user_episode_watched uew ON uew.id_user = w.id_user
            INNER JOIN episode e ON e.id_episode = uew.id_episode AND e.id_serie = s.id_serie
            WHERE w.id_user = :id_user AND w.removed = 0 AND w.archived = 0
            GROUP BY s.id_serie
            ORDER BY MAX(uew.watched_at) DESC
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        $rows   = $this->finalizeRows($rows, $idUser, $idAppacmanLang);

        return array_values(array_filter($rows, static fn(array $row): bool => $row['remaining_episodes'] > 0));
    }

    /**
     * series with zero watched episodes, most-recently-added-to-the-
     * watchlist first. $idAppacmanLang is the current request's already-
     * resolved language (Api\Model\TheTvdb\Languages::idForCulture(Config::
     * getLanguage())) - a LEFT JOIN, not INNER, so a series still shows up
     * even if that language's translation hasn't been synced yet (sl.name/
     * sl.overview just come back null, same as Series/Detail's fallback:
     * sl.name ?: s.default_name). Archived/removed shows (only ever set by
     * the TV Time importer) are excluded - the rows themselves aren't
     * deleted, just hidden from this list. Pagination fetches one extra row
     * (PAGE_SIZE + 1, see pageParams()) purely to detect hasMore without a
     * separate COUNT(*) query - finalizePage() trims it back off.
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listNotStarted(int $idUser, int $idAppacmanLang, int $page): array
    {
        $sql    = '
            SELECT s.*, sl.name, sl.overview
            FROM user_serie_watchlist w
            INNER JOIN serie s ON s.id_serie = w.id_serie
            LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
            WHERE w.id_user = :id_user AND w.removed = 0 AND w.archived = 0
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

        return $this->finalizePage($rows, $idUser, $idAppacmanLang);
    }

    private const array STATUSES = array('all', 'removed', 'archived', 'watching', 'not_started', 'finished');

    /**
     * unified, paginated "browse everything" view (for a profile-style
     * screen) - unlike listWatching()/listNotStarted() above, every status
     * here is paginated, since archived/removed/finished can easily
     * accumulate hundreds of rows (a TV Time import commonly does). The 5
     * non-`all` statuses partition every possible (archived, removed,
     * watched vs. remaining) combination exactly once - `removed` wins over
     * `archived` when a series is somehow flagged as both (confirmed with
     * the user: "removed" is the more definitive state). `all` is the 6th,
     * unfiltered status - every row in the user's watchlist regardless of
     * those flags, same ordering (`w.created DESC`) as the others. $search,
     * when given, further filters by title (translated name, falling back
     * to default_name same as everywhere else) - a simple `LIKE`, no
     * full-text index, matching this app's personal-tracker scale
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listByStatus(int $idUser, int $idAppacmanLang, string $status, int $page, ?string $search = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown watchlist status: ' . $status);
        }

        $hasRemaining = '
            EXISTS (
                SELECT 1
                FROM episode er
                LEFT JOIN user_episode_watched uewr ON uewr.id_episode = er.id_episode AND uewr.id_user = w.id_user
                WHERE er.id_serie = s.id_serie
                  AND er.season_number > 0
                  AND er.aired IS NOT NULL AND er.aired <= CURDATE()
                  AND uewr.id_episode IS NULL
            )
        ';
        $hasWatched = '
            EXISTS (
                SELECT 1
                FROM user_episode_watched uew2
                INNER JOIN episode e2 ON e2.id_episode = uew2.id_episode
                WHERE uew2.id_user = w.id_user AND e2.id_serie = s.id_serie
            )
        ';
        $searchCondition = $search !== null && $search !== ''
            ? ' AND COALESCE(sl.name, s.default_name) LIKE :search '
            : '';

        $sql = match ($status) {
            'all'      => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user' . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'removed'  => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.removed = 1' . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'archived' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.removed = 0 AND w.archived = 1' . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'not_started' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.removed = 0 AND w.archived = 0 AND NOT ' . $hasWatched . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'watching' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.removed = 0 AND w.archived = 0
                  AND ' . $hasWatched . ' AND ' . $hasRemaining . $searchCondition . '
                ORDER BY (
                    SELECT MAX(uew3.watched_at) FROM user_episode_watched uew3
                    INNER JOIN episode e3 ON e3.id_episode = uew3.id_episode
                    WHERE uew3.id_user = w.id_user AND e3.id_serie = s.id_serie
                ) DESC
                LIMIT :limit OFFSET :offset
            ',
            'finished' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.removed = 0 AND w.archived = 0
                  AND ' . $hasWatched . ' AND NOT ' . $hasRemaining . $searchCondition . '
                ORDER BY (
                    SELECT MAX(uew3.watched_at) FROM user_episode_watched uew3
                    INNER JOIN episode e3 ON e3.id_episode = uew3.id_episode
                    WHERE uew3.id_user = w.id_user AND e3.id_serie = s.id_serie
                ) DESC
                LIMIT :limit OFFSET :offset
            ',
        };

        $params = $this->pageParams($idUser, $idAppacmanLang, $page);
        if ($searchCondition !== '') {
            $params['search'] = array('value' => '%' . $search . '%', 'type' => PDO::PARAM_STR);
        }

        $rows = $this->mysql->query($sql, $params);

        return $this->finalizePage($rows, $idUser, $idAppacmanLang);
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
    private function finalizePage(array $rows, int $idUser, int $idAppacmanLang): array
    {
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);

        return array('results' => $this->finalizeRows($rows, $idUser, $idAppacmanLang), 'hasMore' => $hasMore);
    }

    private function finalizeRows(array $rows, int $idUser, int $idAppacmanLang): array
    {
        foreach ($rows as &$row) {
            $row['name']     = $row['name'] ?: $row['default_name'];
            $row['overview'] = $row['overview'] ?: $row['default_overview'];

            // watchlist only ever shows the background/fanart, never the
            // poster - confirmed with the user, so `image` is overwritten
            // rather than kept alongside `background`
            $row['image'] = $row['background'];
            unset($row['background']);

            $remaining                  = $this->remainingEpisodes($idUser, (int) $row['id_serie'], $idAppacmanLang);
            $next                       = $remaining[0] ?? null;
            $row['next_episode']        = $next !== null
                ? sprintf('T%d - E%d', $next['season_number'], $next['episode_number'])
                : null;
            $row['next_episode_name']   = $next !== null
                ? ($next['name'] ?: $next['default_name'])
                : null;
            $row['remaining_episodes']  = count($remaining);

            // only reachable for a "not started" series (listWatching()
            // already drops every remaining_episodes = 0 row before it gets
            // here) - distinguishes "hasn't premiered yet" from "already
            // caught up", which look identical (next_episode = null,
            // remaining_episodes = 0) without this: a not-started show can
            // only have remaining_episodes = 0 because nothing's aired yet,
            // since it's zero-watched by definition
            $row['premiere_in_days'] = $next === null
                ? $this->daysUntilPremiere((int) $row['id_serie'])
                : null;
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
     * @return array<int, array{season_number: int, episode_number: int, name: ?string, default_name: ?string}>
     */
    private function remainingEpisodes(int $idUser, int $idSerie, int $idAppacmanLang): array
    {
        $sql    = '
            SELECT e.season_number, e.episode_number, e.default_name, el.name
            FROM episode e
            LEFT JOIN user_episode_watched w ON w.id_episode = e.id_episode AND w.id_user = :id_user
            LEFT JOIN episode_lang el ON el.id_episode = e.id_episode AND el.id_appacman_lang = :id_appacman_lang
            WHERE e.id_serie = :id_serie
              AND e.season_number > 0
              AND e.aired IS NOT NULL AND e.aired <= CURDATE()
              AND w.id_episode IS NULL
            ORDER BY e.season_number ASC, e.episode_number ASC
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    /**
     * days until the earliest still-upcoming aired date among $idSerie's
     * regular episodes - null when there isn't one (already airing, ended,
     * or TheTVDB just doesn't have a date yet)
     */
    private function daysUntilPremiere(int $idSerie): ?int
    {
        $sql    = '
            SELECT DATEDIFF(MIN(e.aired), CURDATE()) AS days
            FROM episode e
            WHERE e.id_serie = :id_serie
              AND e.season_number > 0
              AND e.aired IS NOT NULL AND e.aired > CURDATE()
        ';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $days   = $this->mysql->query($sql, $params)[0]['days'] ?? null;

        return $days !== null ? (int) $days : null;
    }

}

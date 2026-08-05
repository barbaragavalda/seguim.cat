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
     * an upsert rather than add()'s INSERT IGNORE so a show already in the
     * watchlist still gets synced/kept up to date, but watch_later/
     * stopped_watching are deliberately only ever set on the *first* insert,
     * never touched again on a later conflict. A previous version of this
     * method re-set them unconditionally on every call, meaning simply
     * re-running an import (e.g. to pick up newly-recoverable episodes -
     * see Api\Model\TheTvdb\Client::getAllEpisodePages()) silently reset
     * every manual watch_later/stopped_watching choice the user had made by
     * hand since the previous import back to whatever the export itself
     * says - confirmed to happen for real (3 of 5 manually-set "veure més
     * tard" shows lost that flag on a reconciliation re-import before this
     * was caught). $createdAt preserves TV Time's own follow date (falls
     * back to "now" when the export has none - see Parser::parse()'s
     * "removed" shows) so listNotStarted()'s "most recently added" ordering
     * stays meaningful for imported shows instead of every one of them
     * tying for the import's own timestamp - also only ever set on insert,
     * same reasoning.
     */
    public function addFromImport(int $idUser, int $idSerie, bool $archived, bool $removed, ?string $createdAt = null): void
    {
        $sql    = '
            INSERT INTO user_serie_watchlist (id_user, id_serie, watch_later, stopped_watching, created)
            VALUES (:id_user, :id_serie, :watch_later, :stopped_watching, :created)
            ON DUPLICATE KEY UPDATE id_serie = id_serie
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'watch_later'      => array('value' => (int) $archived, 'type' => PDO::PARAM_INT),
            'stopped_watching' => array('value' => (int) $removed, 'type' => PDO::PARAM_INT),
            'created'          => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
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
            SET watch_later = :watch_later
            WHERE id_user = :id_user AND id_serie = :id_serie
        ';
        $params = array(
            'id_user'     => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie'    => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'watch_later' => array('value' => (int) $archived, 'type' => PDO::PARAM_INT),
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
            SET stopped_watching = :stopped_watching
            WHERE id_user = :id_user AND id_serie = :id_serie
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'stopped_watching' => array('value' => (int) $removed, 'type' => PDO::PARAM_INT),
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
            SELECT watch_later, stopped_watching
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
            'archived'    => (bool) $rows[0]['watch_later'],
            'removed'     => (bool) $rows[0]['stopped_watching'],
        );
    }

    /**
     * series with at least one watched episode AND the last aired regular
     * episode still unwatched, most-recently-watched first (i.e. "continue
     * watching") - a series drops out of this list once its finale is
     * watched (same "finished" definition as listByStatus()'s own
     * $watchedLastEpisode - see that method's docblock), even if earlier
     * gaps are still unwatched; it reappears on its own if a new episode
     * airs later, since this is computed fresh every call, not stored. Not
     * paginated, unlike listNotStarted(): a personal tracker's in-progress
     * list stays small by nature, so the extra page/hasMore surface isn't
     * worth it here. Archived/removed shows (only ever set by the TV Time
     * importer) are excluded here too, same as listNotStarted() - the rows
     * themselves aren't deleted, just hidden from both lists.
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
            WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 0
            GROUP BY s.id_serie
            ORDER BY MAX(uew.watched_at) DESC
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        $rows   = $this->finalizeRows($rows, $idUser, $idAppacmanLang);

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => !$this->hasWatchedLastEpisode($idUser, (int) $row['id_serie']),
        ));
    }

    /**
     * whether $idUser has watched $idSerie's last aired regular episode -
     * the shared "finished" definition behind listByStatus()'s own
     * $watchedLastEpisode SQL fragment and listWatching()'s own filter, see
     * either one's docblock. A PHP-side per-series helper (rather than a
     * SQL fragment like listByStatus() needs) since listWatching() already
     * loops its rows one at a time in finalizeRows() to compute
     * next_episode/remaining_episodes the same way. Public so
     * Api\Model\TvTimeImport\Processor/SeriesImportPending can reuse the
     * exact same definition to force watch_later/stopped_watching off a
     * show the user has already finished, regardless of what TheTVDB's own
     * (unrelated) status field says or what TV Time's export claims.
     */
    public function hasWatchedLastEpisode(int $idUser, int $idSerie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_episode_watched uew
            WHERE uew.id_user = :id_user
              AND uew.id_episode = (
                  SELECT e.id_episode
                  FROM episode e
                  WHERE e.id_serie = :id_serie
                    AND e.season_number > 0
                    AND e.aired IS NOT NULL AND e.aired <= CURDATE()
                  ORDER BY e.season_number DESC, e.episode_number DESC
                  LIMIT 1
              )
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
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
            WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 0
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

    private const array STATUSES = array('all', 'removed', 'archived', 'watching', 'not_started', 'finished', 'finished_pending');

    /**
     * unified, paginated "browse everything" view (for a profile-style
     * screen) - unlike listWatching()/listNotStarted() above, every status
     * here is paginated, since archived/removed/finished can easily
     * accumulate hundreds of rows (a TV Time import commonly does). 5 of the
     * non-`all` statuses (removed/archived/not_started/watching/finished)
     * partition every possible (archived, removed, watched vs. remaining)
     * combination exactly once - `removed` wins over `archived` when a
     * series is somehow flagged as both (confirmed with the user: "removed"
     * is the more definitive state). `finished_pending` is not part of that
     * partition - it's a subset of `finished` (every finished_pending row
     * is also finished), for a series whose finale is watched but has an
     * earlier gap - see $hasUnwatchedAired's own docblock. `all` is
     * unfiltered - every row in the user's watchlist regardless of those
     * flags, same ordering (`w.created DESC`) as the others. $search, when
     * given, further filters by title (translated name, falling back to
     * default_name same as everywhere else) - a simple `LIKE`, no full-text
     * index, matching this app's personal-tracker scale
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listByStatus(int $idUser, int $idAppacmanLang, string $status, int $page, ?string $search = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown watchlist status: ' . $status);
        }

        // a series counts as "finished" once its last aired regular episode
        // is watched, even with earlier gaps still unwatched (confirmed
        // with the user: watching the finale is what they mean by
        // "finished", not a full gap-free watch-through) - deliberately NOT
        // "no unwatched aired episodes anywhere", which used to be this
        // status' definition
        $watchedLastEpisode = '
            EXISTS (
                SELECT 1
                FROM user_episode_watched uewl
                WHERE uewl.id_user = w.id_user
                  AND uewl.id_episode = (
                      SELECT le.id_episode
                      FROM episode le
                      WHERE le.id_serie = s.id_serie
                        AND le.season_number > 0
                        AND le.aired IS NOT NULL AND le.aired <= CURDATE()
                      ORDER BY le.season_number DESC, le.episode_number DESC
                      LIMIT 1
                  )
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
        // an aired episode still unwatched - combined with $watchedLastEpisode
        // below, this can only be an earlier gap (the finale itself is
        // guaranteed watched), which is exactly what "finished_pending"
        // means: confirmed with the user that this is a separate, additional
        // status alongside "finished" (every finished_pending row is also
        // finished - see that status' own definition just above), not a
        // replacement definition of it. Deliberately NOT season_number > 0
        // only, unlike $watchedLastEpisode/remainingEpisodes() elsewhere - an
        // unwatched special counts as "pending" here too (confirmed with the
        // user), even though it never decides "finished" itself
        $hasUnwatchedAired = '
            EXISTS (
                SELECT 1
                FROM episode e4
                WHERE e4.id_serie = s.id_serie
                  AND e4.aired IS NOT NULL AND e4.aired <= CURDATE()
                  AND NOT EXISTS (
                      SELECT 1
                      FROM user_episode_watched uew4
                      WHERE uew4.id_user = w.id_user AND uew4.id_episode = e4.id_episode
                  )
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
                WHERE w.id_user = :id_user AND w.stopped_watching = 1' . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'archived' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 1' . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'not_started' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 0 AND NOT ' . $hasWatched . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'watching' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 0
                  AND ' . $hasWatched . ' AND NOT ' . $watchedLastEpisode . $searchCondition . '
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
                WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 0
                  AND ' . $hasWatched . ' AND ' . $watchedLastEpisode . $searchCondition . '
                ORDER BY (
                    SELECT MAX(uew3.watched_at) FROM user_episode_watched uew3
                    INNER JOIN episode e3 ON e3.id_episode = uew3.id_episode
                    WHERE uew3.id_user = w.id_user AND e3.id_serie = s.id_serie
                ) DESC
                LIMIT :limit OFFSET :offset
            ',
            // a subset of 'finished' above, not a different definition of
            // it - see $hasUnwatchedAired's own docblock
            'finished_pending' => '
                SELECT s.*, sl.name, sl.overview
                FROM user_serie_watchlist w
                INNER JOIN serie s ON s.id_serie = w.id_serie
                LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND w.stopped_watching = 0 AND w.watch_later = 0
                  AND ' . $watchedLastEpisode . ' AND ' . $hasUnwatchedAired . $searchCondition . '
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
        // `image` (poster) and `background` (fanart) both go out as-is now
        // - the landscape watchlist row only ever wanted the fanart, but
        // the app's "My series" poster grid (same endpoint, different
        // screen) needs the actual poster, so neither can overwrite the
        // other any more
        $progress = (new Episode())->watchProgressForSeries(
            $idUser,
            array_map(static fn(array $r): int => (int) $r['id_serie'], $rows),
        );

        foreach ($rows as &$row) {
            $row['name']     = $row['name'] ?: $row['default_name'];
            $row['overview'] = $row['overview'] ?: $row['default_overview'];

            $idSerie = (int) $row['id_serie'];
            if (isset($progress[$idSerie])) {
                $row['watched_episodes'] = $progress[$idSerie]['watched'];
                $row['total_episodes']   = $progress[$idSerie]['total'];
            }

            $remaining                  = $this->remainingEpisodes($idUser, (int) $row['id_serie'], $idAppacmanLang);
            $next                       = $remaining[0] ?? null;
            $row['next_episode']        = $next !== null
                ? sprintf('T%d - E%d', $next['season_number'], $next['episode_number'])
                : null;
            $row['next_episode_name']   = $next !== null
                ? ($next['name'] ?: $next['default_name'])
                : null;
            // lets the watchlist row mark this one specific episode watched
            // directly (same endpoint the series detail screen's own
            // episode rows already use), without a second lookup
            $row['next_episode_tvdb_id'] = $next['tvdb_id'] ?? null;
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
     * @return array<int, array{season_number: int, episode_number: int, name: ?string, default_name: ?string, tvdb_id: int}>
     */
    private function remainingEpisodes(int $idUser, int $idSerie, int $idAppacmanLang): array
    {
        $sql    = '
            SELECT e.season_number, e.episode_number, e.default_name, el.name, e.tvdb_id
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

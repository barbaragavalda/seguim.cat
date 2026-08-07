<?php

namespace Api\Model;

use Api\Model\Concerns\PaginatesByLanguage;
use Core\Model\Model;
use PDO;

class Watchlist extends Model
{
    use PaginatesByLanguage;

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
     * Used only by the TV Time importer - an upsert, but watch_later/stopped_watching are
     * only ever set on the *first* insert, never touched on a later conflict: an earlier
     * version re-set them unconditionally, so simply re-running an import silently
     * reverted manual archive/remove choices back to the export's values (confirmed: 3 of
     * 5 manually-archived shows lost that flag on a reconciliation re-import). $createdAt
     * is preserved the same way, for the same reason.
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

    /** User-driven archive/unarchive - same `archived` flag the TV Time importer sets. No-op if $idSerie isn't in the user's watchlist */
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

    /** User-driven mark-removed/restore - unlike remove() below, keeps the row and just hides it from both listings, same `removed` flag the TV Time importer sets */
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

    /** Hard delete - the row is gone entirely, unlike setRemoved()'s soft hide above. Watched-episode history isn't touched (a separate table) */
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
     * Used by Series\Detail to show the archived/removed toggles' current state, not just
     * the plain has() flag. A row not in the watchlist comes back as every flag false.
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
     * Series with at least one watched episode AND the last aired regular episode still
     * unwatched, most-recently-watched first ("continue watching") - drops out once the
     * finale is watched (same definition as listByStatus()'s $watchedLastEpisode),
     * computed fresh every call, not stored. Not paginated, unlike listNotStarted(): an
     * in-progress list stays small by nature. Archived/removed shows are excluded here too.
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
     * Whether $idUser has watched $idSerie's last aired regular episode - the shared
     * "finished" definition behind listByStatus()'s $watchedLastEpisode and
     * listWatching()'s filter. Public so TvTimeImport\Processor/SeriesImportPending can
     * reuse it to force watch_later/stopped_watching off an already-finished show,
     * regardless of TheTVDB's status field or TV Time's export.
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
     * Series with zero watched episodes, most-recently-added first. LEFT JOIN (not INNER)
     * on serie_lang so a series still shows up even if that language's translation hasn't
     * synced yet (falls back to default_name, same as Series/Detail). Archived/removed
     * shows are excluded. Pagination fetches one extra row (see pageParams()) to detect
     * hasMore without a separate COUNT(*) query.
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
     * Unified, paginated "browse everything" view - unlike listWatching()/listNotStarted()
     * above, every status here is paginated, since archived/removed/finished can easily
     * accumulate hundreds of rows. 5 of the non-`all` statuses partition every (archived,
     * removed, watched vs. remaining) combination exactly once - `removed` wins over
     * `archived` when both are set (confirmed with the user). `finished_pending` is a
     * subset of `finished`, not part of that partition - see $hasUnwatchedAired's docblock.
     * $search filters by title with a simple LIKE, no full-text index, matching this app's
     * personal-tracker scale.
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listByStatus(int $idUser, int $idAppacmanLang, string $status, int $page, ?string $search = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown watchlist status: ' . $status);
        }

        // "finished" = last aired regular episode watched, even with earlier gaps
        // unwatched (confirmed with the user) - deliberately NOT "no unwatched aired
        // episodes anywhere", which used to be this status' definition
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
        // An aired episode still unwatched; combined with $watchedLastEpisode this can
        // only be an earlier gap, which is "finished_pending" - a separate status
        // alongside "finished", not a replacement (confirmed with the user).
        // Deliberately not season_number > 0 only: an unwatched special counts as
        // "pending" here too, even though it never decides "finished" itself.
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
            // subset of 'finished' above, not a different definition - see $hasUnwatchedAired's docblock
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
        // image (poster) and background (fanart) both go out as-is - the poster grid
        // needs image while the landscape row needs background, so neither can
        // overwrite the other
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
            // lets the watchlist row mark this episode watched directly, same endpoint
            // series detail's episode rows already use
            $row['next_episode_tvdb_id'] = $next['tvdb_id'] ?? null;
            $row['remaining_episodes']  = count($remaining);

            // only reachable for a not-started series (listWatching() already drops
            // remaining_episodes = 0 rows) - distinguishes "hasn't premiered yet" from
            // "already caught up", which otherwise look identical (next_episode = null,
            // remaining_episodes = 0)
            $row['premiere_in_days'] = $next === null
                ? $this->daysUntilPremiere((int) $row['id_serie'])
                : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Unwatched episodes of $idSerie strictly after the user's furthest watched one,
     * oldest first - not simply "every unwatched episode": a gap way back in a
     * long-running show must not get stuck being offered as "next episode" once the user
     * is caught up further. Season 0 (specials) is always excluded, same as
     * Series/Detail's season_count: TheTVDB has no reliable field to tell plot-relevant
     * specials from clip-shows (checked empirically against Lost and Euphoria), so
     * there's no sound way to count only the ones that matter.
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
            LEFT JOIN (
                SELECT fe.season_number, fe.episode_number
                FROM episode fe
                INNER JOIN user_episode_watched fw ON fw.id_episode = fe.id_episode AND fw.id_user = :id_user
                WHERE fe.id_serie = :id_serie AND fe.season_number > 0
                ORDER BY fe.season_number DESC, fe.episode_number DESC
                LIMIT 1
            ) f ON 1 = 1
            WHERE e.id_serie = :id_serie
              AND e.season_number > 0
              AND e.aired IS NOT NULL AND e.aired <= CURDATE()
              AND w.id_episode IS NULL
              AND (
                  f.season_number IS NULL
                  OR e.season_number > f.season_number
                  OR (e.season_number = f.season_number AND e.episode_number > f.episode_number)
              )
            ORDER BY e.season_number ASC, e.episode_number ASC
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    /** Days until the earliest upcoming aired date - null when there isn't one (already airing, ended, or no date yet) */
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

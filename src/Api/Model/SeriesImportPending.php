<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

/**
 * A show whose TV Time tv_show_id no longer resolves on TheTVDB at all -
 * Api\Model\TvTimeImport\SeriesMatcher's own name search couldn't confidently
 * resolve a replacement (several same-titled candidates, or none) - waiting
 * for the user to pick the right one by hand (or dismiss it). Mirrors
 * MovieImportPending, except the watched/rewatch snapshot is keyed by
 * season+episode NUMBER rather than TheTVDB episode id - see this table's
 * own docblock in db.sql for why.
 *
 * resolve()/skip() mark a row `resolved` instead of deleting it - see
 * MovieImportPending's own docblock on why, identical reasoning here.
 */
class SeriesImportPending extends Model
{

    /**
     * @param array{archived: bool, removed: bool, created_at: ?string} $flags
     * @param array<int, array{season: int, episode: int, at: string}> $watchedEpisodes
     * @param array<int, array{season: int, episode: int, cpt: int, at: string}> $rewatchEpisodes
     * @param array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}> $candidates
     */
    public function createOrUpdate(
        int $idUser,
        int $idTvtimeImport,
        string $showName,
        array $flags,
        array $watchedEpisodes,
        array $rewatchEpisodes,
        array $candidates
    ): void {
        $sql    = '
            INSERT INTO user_serie_pending
                (id_user, id_tvtime_import, show_name, watch_later, stopped_watching, watchlist_created_at, watched_episodes, rewatch_episodes, candidates)
            VALUES
                (:id_user, :id_tvtime_import, :show_name, :watch_later, :stopped_watching, :watchlist_created_at, :watched_episodes, :rewatch_episodes, :candidates)
            ON DUPLICATE KEY UPDATE
                id_tvtime_import = :id_tvtime_import_upd, watch_later = :watch_later_upd, stopped_watching = :stopped_watching_upd,
                watchlist_created_at = :watchlist_created_at_upd, watched_episodes = :watched_episodes_upd,
                rewatch_episodes = :rewatch_episodes_upd, candidates = :candidates_upd
        ';
        $params = array(
            'id_user'                    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_tvtime_import'           => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
            'id_tvtime_import_upd'       => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
            'show_name'                  => array('value' => $showName, 'type' => PDO::PARAM_STR),
            'watch_later'                => array('value' => $flags['archived'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'watch_later_upd'            => array('value' => $flags['archived'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'stopped_watching'           => array('value' => $flags['removed'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'stopped_watching_upd'       => array('value' => $flags['removed'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'watchlist_created_at'       => array('value' => $flags['created_at'], 'type' => PDO::PARAM_STR),
            'watchlist_created_at_upd'   => array('value' => $flags['created_at'], 'type' => PDO::PARAM_STR),
            'watched_episodes'           => array('value' => json_encode($watchedEpisodes), 'type' => PDO::PARAM_STR),
            'watched_episodes_upd'       => array('value' => json_encode($watchedEpisodes), 'type' => PDO::PARAM_STR),
            'rewatch_episodes'           => array('value' => json_encode($rewatchEpisodes), 'type' => PDO::PARAM_STR),
            'rewatch_episodes_upd'       => array('value' => json_encode($rewatchEpisodes), 'type' => PDO::PARAM_STR),
            'candidates'                 => array('value' => json_encode($candidates), 'type' => PDO::PARAM_STR),
            'candidates_upd'             => array('value' => json_encode($candidates), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * Records that $idUserList wants this pending show as a member, once
     * resolved - see user_serie_list_pending's own docblock in db.sql.
     * Idempotent (a re-import or a resumed batch hitting the same list/show
     * pair again is a no-op) via INSERT IGNORE rather than a SELECT-then-
     * INSERT, since the table's own unique key already guarantees this
     * safely under concurrent/repeated calls.
     */
    public function linkList(int $idSeriesImportPending, int $idUserList, ?string $addedAt): void
    {
        $sql    = '
            INSERT IGNORE INTO user_serie_list_pending (id_user_serie_pending, id_user_list, added_at)
            VALUES (:id_pending, :id_user_list, :added_at)
        ';
        $params = array(
            'id_pending'   => array('value' => $idSeriesImportPending, 'type' => PDO::PARAM_INT),
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'added_at'     => array('value' => $addedAt, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * @return array<int, array{id_user_list: int, added_at: ?string}>
     */
    private function linkedLists(int $idSeriesImportPending): array
    {
        $sql    = '
            SELECT id_user_list, added_at
            FROM user_serie_list_pending
            WHERE id_user_serie_pending = :id_pending
        ';
        $params = array('id_pending' => array('value' => $idSeriesImportPending, 'type' => PDO::PARAM_INT));
        $rows   = $this->mysql->query($sql, $params);

        return array_map(
            static fn(array $row): array => array('id_user_list' => (int) $row['id_user_list'], 'added_at' => $row['added_at']),
            $rows
        );
    }

    /**
     * how many of $idUserList's own series are still waiting on a pending
     * row - for the app's own "X of Y imported" list indicator. Excludes
     * already-resolved/dismissed rows - see MovieImportPending::
     * pendingCountForList()'s own docblock, identical reasoning
     */
    public function pendingCountForList(int $idUserList): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM user_serie_list_pending sipl
            JOIN user_serie_pending sip ON sip.id_user_serie_pending = sipl.id_user_serie_pending
            WHERE sipl.id_user_list = :id_user_list AND sip.resolved = 0
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
    }

    /**
     * whether $showName already has a resolved/dismissed row for $idUser -
     * see MovieImportPending::isResolved()'s own docblock, identical
     * reasoning
     */
    public function isResolved(int $idUser, string $showName): bool
    {
        $sql    = '
            SELECT 1
            FROM user_serie_pending
            WHERE id_user = :id_user AND show_name = :show_name AND resolved = 1
            LIMIT 1
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'show_name' => array('value' => $showName, 'type' => PDO::PARAM_STR),
        );
        return isset($this->mysql->query($sql, $params)[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $idUser): array
    {
        $sql    = '
            SELECT *
            FROM user_serie_pending
            WHERE id_user = :id_user AND resolved = 0
            ORDER BY created ASC
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $rows   = $this->mysql->query($sql, $params);

        foreach ($rows as &$row) {
            $row['candidates'] = json_decode($row['candidates'], true) ?? array();
        }
        unset($row);

        return $rows;
    }

    /**
     * looks up a pending row's own id by its unique key instead of by its
     * primary key - createOrUpdate() is an upsert and doesn't return which
     * row it touched, and Processor::processLists() needs this id right
     * after to link a list to it (see linkList())
     */
    public function idForShowName(int $idUser, string $showName): ?int
    {
        $sql    = '
            SELECT id_user_serie_pending
            FROM user_serie_pending
            WHERE id_user = :id_user AND show_name = :show_name
            LIMIT 1
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'show_name' => array('value' => $showName, 'type' => PDO::PARAM_STR),
        );
        $rows   = $this->mysql->query($sql, $params);
        return isset($rows[0]) ? (int) $rows[0]['id_user_serie_pending'] : null;
    }

    private function findOwnedByUser(int $id, int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM user_serie_pending
            WHERE id_user_serie_pending = :id AND id_user = :id_user
            LIMIT 1
        ';
        $params = array(
            'id'      => array('value' => $id, 'type' => PDO::PARAM_INT),
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        return $rows[0] ?? null;
    }

    /**
     * Applies the user's chosen TheTVDB series for a pending show - syncs
     * it/them and their episodes, then replays the watchlist/watched/
     * rewatch state snapshotted at import time by matching each stored
     * {season, episode} pair against the newly-synced episode list (the old
     * export's own episode ids are exactly as dead as the show id was - see
     * this class' own docblock).
     *
     * $tvdbIds can have more than one entry, same reasoning as
     * MovieImportPending::resolve() (e.g. a same-titled reboot watched under
     * one ambiguous TV Time entry) - applied independently to each.
     *
     * @param array<int> $tvdbIds
     * @return ?bool null if no such pending row belongs to this user, false
     *               if the row exists but none of the chosen ids resolve on
     *               TheTVDB right now (e.g. a candidate TheTVDB has since
     *               merged/deleted - confirmed to happen in practice: two
     *               candidates sharing a name/year, one already dead)
     */
    public function resolve(int $id, int $idUser, array $tvdbIds, Client $client): ?bool
    {
        $pending = $this->findOwnedByUser($id, $idUser);
        if ($pending === null) {
            return null;
        }

        $watchedEpisodes = json_decode($pending['watched_episodes'], true) ?? array();
        $rewatchEpisodes = json_decode($pending['rewatch_episodes'], true) ?? array();
        $linkedLists     = $this->linkedLists((int) $pending['id_user_serie_pending']);
        $userListSerie   = new UserListSerie();
        $watchlist       = new Watchlist();

        $appliedAny = false;
        foreach ($tvdbIds as $tvdbId) {
            $series = new Series();
            $info   = $series->sync($tvdbId, $client);
            if (empty($info)) {
                // one bad id among several shouldn't block the others
                continue;
            }

            $episodeRows = (new Episode())->syncForSeries($info['id_serie'], $tvdbId, $client);
            $idEpisodeBySeasonEpisode = array();
            foreach ($episodeRows as $episodeRow) {
                $key = $episodeRow['season_number'] . '.' . $episodeRow['episode_number'];
                $idEpisodeBySeasonEpisode[$key] = $episodeRow['id_episode'];
            }

            $watchlist->addFromImport(
                $idUser,
                $info['id_serie'],
                (bool) $pending['watch_later'],
                (bool) $pending['stopped_watching'],
                $pending['watchlist_created_at']
            );

            $watchedEpisode = new WatchedEpisode();
            foreach ($watchedEpisodes as $watched) {
                $idEpisode = $idEpisodeBySeasonEpisode[$watched['season'] . '.' . $watched['episode']] ?? null;
                if ($idEpisode === null) {
                    continue;
                }
                $watchedEpisode->markWatched($idUser, (int) $idEpisode, $watched['at']);
            }
            foreach ($rewatchEpisodes as $rewatch) {
                $idEpisode = $idEpisodeBySeasonEpisode[$rewatch['season'] . '.' . $rewatch['episode']] ?? null;
                if ($idEpisode === null) {
                    continue;
                }
                for ($i = 0; $i < $rewatch['cpt']; $i++) {
                    $watchedEpisode->markRewatched($idUser, (int) $idEpisode, $rewatch['at']);
                }
            }

            // this show was also wanted as a member of one or more lists
            // (Processor::processLists() linked it here instead of silently
            // dropping it - see user_serie_list_pending's own docblock) -
            // now that it's actually synced, add it to each of them too
            foreach ($linkedLists as $linked) {
                $userListSerie->add($linked['id_user_list'], (int) $info['id_serie'], $linked['added_at']);
            }

            // same "user's own watch history decides finished, not
            // TheTVDB's status" reasoning as Processor's own two call sites
            if ($watchlist->hasWatchedLastEpisode($idUser, $info['id_serie'])) {
                $watchlist->setArchived($idUser, $info['id_serie'], false);
                $watchlist->setRemoved($idUser, $info['id_serie'], false);
            }

            $appliedAny = true;
        }

        if (!$appliedAny) {
            return false;
        }

        $this->markResolved((int) $pending['id_user_serie_pending']);
        return true;
    }

    /**
     * Dismisses a pending show without applying anything.
     *
     * @return bool false if no such pending row belongs to this user
     */
    public function skip(int $id, int $idUser): bool
    {
        $pending = $this->findOwnedByUser($id, $idUser);
        if ($pending === null) {
            return false;
        }
        $this->markResolved((int) $pending['id_user_serie_pending']);
        return true;
    }

    /**
     * see MovieImportPending::markResolved()'s own docblock, identical
     * reasoning
     */
    private function markResolved(int $id): void
    {
        $sql    = '
            UPDATE user_serie_pending
            SET resolved = 1
            WHERE id_user_serie_pending = :id
        ';
        $params = array('id' => array('value' => $id, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_serie_pending
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

}

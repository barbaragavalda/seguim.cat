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
            INSERT INTO series_import_pending
                (id_user, id_tvtime_import, show_name, archived, removed, watchlist_created_at, watched_episodes, rewatch_episodes, candidates)
            VALUES
                (:id_user, :id_tvtime_import, :show_name, :archived, :removed, :watchlist_created_at, :watched_episodes, :rewatch_episodes, :candidates)
            ON DUPLICATE KEY UPDATE
                id_tvtime_import = :id_tvtime_import_upd, archived = :archived_upd, removed = :removed_upd,
                watchlist_created_at = :watchlist_created_at_upd, watched_episodes = :watched_episodes_upd,
                rewatch_episodes = :rewatch_episodes_upd, candidates = :candidates_upd
        ';
        $params = array(
            'id_user'                    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_tvtime_import'           => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
            'id_tvtime_import_upd'       => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
            'show_name'                  => array('value' => $showName, 'type' => PDO::PARAM_STR),
            'archived'                   => array('value' => $flags['archived'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'archived_upd'               => array('value' => $flags['archived'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'removed'                    => array('value' => $flags['removed'] ? 1 : 0, 'type' => PDO::PARAM_INT),
            'removed_upd'                => array('value' => $flags['removed'] ? 1 : 0, 'type' => PDO::PARAM_INT),
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
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $idUser): array
    {
        $sql    = '
            SELECT *
            FROM series_import_pending
            WHERE id_user = :id_user
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

    private function findOwnedByUser(int $id, int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM series_import_pending
            WHERE id_series_import_pending = :id AND id_user = :id_user
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

            (new Watchlist())->addFromImport(
                $idUser,
                $info['id_serie'],
                (bool) $pending['archived'],
                (bool) $pending['removed'],
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

            $appliedAny = true;
        }

        if (!$appliedAny) {
            return false;
        }

        $this->delete((int) $pending['id_series_import_pending']);
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
        $this->delete((int) $pending['id_series_import_pending']);
        return true;
    }

    private function delete(int $id): void
    {
        $sql    = '
            DELETE FROM series_import_pending
            WHERE id_series_import_pending = :id
        ';
        $params = array('id' => array('value' => $id, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM series_import_pending
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

}

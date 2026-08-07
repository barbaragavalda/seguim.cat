<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

/**
 * A movie title MovieMatcher couldn't confidently resolve, waiting for the
 * user to pick by hand (or dismiss) - see MovieMatcher's own docblock.
 *
 * resolve()/skip() mark rows resolved instead of deleting: the title is
 * exactly as ambiguous on a later import, so isResolved() prevents
 * re-surfacing an already-answered question.
 */
class MovieImportPending extends Model
{

    /**
     * @param array{watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>} $entry
     * @param array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}> $candidates
     */
    public function createOrUpdate(
        int $idUser,
        string $movieName,
        ?string $expectedYear,
        array $entry,
        array $candidates
    ): void {
        $sql    = '
            INSERT INTO user_movie_pending
                (id_user, movie_name, expected_year, watchlist_created_at, watched_at, rewatch_at, candidates)
            VALUES
                (:id_user, :movie_name, :expected_year, :watchlist_created_at, :watched_at, :rewatch_at, :candidates)
            ON DUPLICATE KEY UPDATE
                expected_year = :expected_year_upd,
                watchlist_created_at = :watchlist_created_at_upd, watched_at = :watched_at_upd,
                rewatch_at = :rewatch_at_upd, candidates = :candidates_upd
        ';
        $params = array(
            'id_user'                  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'movie_name'               => array('value' => $movieName, 'type' => PDO::PARAM_STR),
            'expected_year'            => array('value' => $expectedYear, 'type' => PDO::PARAM_STR),
            'expected_year_upd'        => array('value' => $expectedYear, 'type' => PDO::PARAM_STR),
            'watchlist_created_at'     => array('value' => $entry['watchlist_created_at'], 'type' => PDO::PARAM_STR),
            'watchlist_created_at_upd' => array('value' => $entry['watchlist_created_at'], 'type' => PDO::PARAM_STR),
            'watched_at'               => array('value' => $entry['watched_at'], 'type' => PDO::PARAM_STR),
            'watched_at_upd'           => array('value' => $entry['watched_at'], 'type' => PDO::PARAM_STR),
            'rewatch_at'               => array('value' => json_encode($entry['rewatch_at']), 'type' => PDO::PARAM_STR),
            'rewatch_at_upd'           => array('value' => json_encode($entry['rewatch_at']), 'type' => PDO::PARAM_STR),
            'candidates'               => array('value' => json_encode($candidates), 'type' => PDO::PARAM_STR),
            'candidates_upd'           => array('value' => json_encode($candidates), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * see SeriesImportPending::linkList()'s own docblock - identical shape
     */
    public function linkList(int $idMovieImportPending, int $idUserList, ?string $addedAt): void
    {
        $sql    = '
            INSERT IGNORE INTO user_movie_list_pending (id_user_movie_pending, id_user_list, added_at)
            VALUES (:id_pending, :id_user_list, :added_at)
        ';
        $params = array(
            'id_pending'   => array('value' => $idMovieImportPending, 'type' => PDO::PARAM_INT),
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'added_at'     => array('value' => $addedAt, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * @return array<int, array{id_user_list: int, added_at: ?string}>
     */
    private function linkedLists(int $idMovieImportPending): array
    {
        $sql    = '
            SELECT id_user_list, added_at
            FROM user_movie_list_pending
            WHERE id_user_movie_pending = :id_pending
        ';
        $params = array('id_pending' => array('value' => $idMovieImportPending, 'type' => PDO::PARAM_INT));
        $rows   = $this->mysql->query($sql, $params);

        return array_map(
            static fn(array $row): array => array('id_user_list' => (int) $row['id_user_list'], 'added_at' => $row['added_at']),
            $rows
        );
    }

    /**
     * count of $idUserList's still-pending movies, for the app's "X of Y
     * imported" indicator - resolved/dismissed rows excluded (see
     * `resolved` in db.sql)
     */
    public function pendingCountForList(int $idUserList): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM user_movie_list_pending mipl
            JOIN user_movie_pending mip ON mip.id_user_movie_pending = mipl.id_user_movie_pending
            WHERE mipl.id_user_list = :id_user_list AND mip.resolved = 0
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
    }

    /**
     * whether $movieName already has a resolved/dismissed row - checked
     * before re-matching so an already-answered question doesn't resurface
     */
    public function isResolved(int $idUser, string $movieName): bool
    {
        $sql    = '
            SELECT 1
            FROM user_movie_pending
            WHERE id_user = :id_user AND movie_name = :movie_name AND resolved = 1
            LIMIT 1
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'movie_name' => array('value' => $movieName, 'type' => PDO::PARAM_STR),
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
            FROM user_movie_pending
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
     * see SeriesImportPending::idForShowName()'s own docblock, identical
     * reasoning
     */
    public function idForMovieName(int $idUser, string $movieName): ?int
    {
        $sql    = '
            SELECT id_user_movie_pending
            FROM user_movie_pending
            WHERE id_user = :id_user AND movie_name = :movie_name
            LIMIT 1
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'movie_name' => array('value' => $movieName, 'type' => PDO::PARAM_STR),
        );
        $rows   = $this->mysql->query($sql, $params);
        return isset($rows[0]) ? (int) $rows[0]['id_user_movie_pending'] : null;
    }

    private function findOwnedByUser(int $id, int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM user_movie_pending
            WHERE id_user_movie_pending = :id AND id_user = :id_user
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
     * Applies the user's chosen TheTVDB movie(s), replaying the snapshotted
     * watchlist/watched/rewatch state, then removes the pending row.
     *
     * $watchedTvdbIds/$pendingTvdbIds can together have more than one entry
     * - TV Time sometimes can't tell two real movies apart under one title
     * (e.g. "Mulan" 1998 vs 2020) with no release date to disambiguate, so
     * the user splits watched/unwatched across candidates by hand here.
     *
     * @return ?bool null if no such pending row belongs to this user, false
     *               if none of the chosen ids resolve on TheTVDB right now
     *               (e.g. a candidate has since been merged/deleted)
     */
    public function resolve(int $id, int $idUser, array $watchedTvdbIds, array $pendingTvdbIds, Client $client): ?bool
    {
        $pending = $this->findOwnedByUser($id, $idUser);
        if ($pending === null) {
            return null;
        }

        $linkedLists   = $this->linkedLists((int) $pending['id_user_movie_pending']);
        $userListMovie = new UserListMovie();

        $appliedAny = false;
        foreach ($watchedTvdbIds as $tvdbId) {
            $info = $this->applyCandidate($idUser, (int) $tvdbId, $pending, $linkedLists, $userListMovie, $client);
            if ($info === null) {
                continue;
            }

            if ($pending['watched_at'] !== null) {
                (new WatchedMovie())->markWatched($idUser, $info['id_movie'], $pending['watched_at']);
            }
            foreach (json_decode($pending['rewatch_at'], true) ?? array() as $rewatchAt) {
                (new WatchedMovie())->markRewatched($idUser, $info['id_movie'], $rewatchAt);
            }
            $appliedAny = true;
        }
        foreach ($pendingTvdbIds as $tvdbId) {
            $info = $this->applyCandidate($idUser, (int) $tvdbId, $pending, $linkedLists, $userListMovie, $client);
            if ($info !== null) {
                $appliedAny = true;
            }
        }

        if (!$appliedAny) {
            return false;
        }

        $this->markResolved((int) $pending['id_user_movie_pending']);
        return true;
    }

    /**
     * Syncs one candidate and adds it to the watchlist + linked lists -
     * shared by both watched and pending-only candidates in resolve().
     *
     * @return ?array the synced movie's info row, null if it doesn't resolve
     */
    private function applyCandidate(int $idUser, int $tvdbId, array $pending, array $linkedLists, UserListMovie $userListMovie, Client $client): ?array
    {
        $movie = new Movie();
        $info  = $movie->sync($tvdbId, $client);
        if (empty($info)) {
            // one bad id among several shouldn't block the others
            return null;
        }

        (new MovieWatchlist())->addFromImport($idUser, $info['id_movie'], $pending['watchlist_created_at']);

        // also wanted in other lists (see user_movie_list_pending's own
        // docblock) - add now that it's synced
        foreach ($linkedLists as $linked) {
            $userListMovie->add($linked['id_user_list'], (int) $info['id_movie'], $linked['added_at']);
        }

        return $info;
    }

    /**
     * Dismisses a pending title without applying anything.
     *
     * @return bool false if no such pending row belongs to this user
     */
    public function skip(int $id, int $idUser): bool
    {
        $pending = $this->findOwnedByUser($id, $idUser);
        if ($pending === null) {
            return false;
        }
        $this->markResolved((int) $pending['id_user_movie_pending']);
        return true;
    }

    /**
     * marks a row handled instead of deleting it - see `resolved` in
     * db.sql for why it must survive
     */
    private function markResolved(int $id): void
    {
        $sql    = '
            UPDATE user_movie_pending
            SET resolved = 1
            WHERE id_user_movie_pending = :id
        ';
        $params = array('id' => array('value' => $id, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_movie_pending
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

}

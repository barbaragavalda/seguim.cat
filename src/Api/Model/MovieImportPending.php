<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

/**
 * A movie title Api\Model\TvTimeImport\MovieMatcher couldn't confidently
 * resolve, waiting for the user to pick the right one by hand (or dismiss
 * it) - see MovieMatcher's own docblock for why this exists instead of
 * guessing or silently dropping the title.
 */
class MovieImportPending extends Model
{

    /**
     * @param array{watchlist_created_at: ?string, watched_at: ?string, rewatch_at: array<int, string>} $entry
     * @param array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}> $candidates
     */
    public function createOrUpdate(
        int $idUser,
        int $idTvtimeImport,
        string $movieName,
        ?string $expectedYear,
        array $entry,
        array $candidates
    ): void {
        $sql    = '
            INSERT INTO movie_import_pending
                (id_user, id_tvtime_import, movie_name, expected_year, watchlist_created_at, watched_at, rewatch_at, candidates)
            VALUES
                (:id_user, :id_tvtime_import, :movie_name, :expected_year, :watchlist_created_at, :watched_at, :rewatch_at, :candidates)
            ON DUPLICATE KEY UPDATE
                id_tvtime_import = :id_tvtime_import_upd, expected_year = :expected_year_upd,
                watchlist_created_at = :watchlist_created_at_upd, watched_at = :watched_at_upd,
                rewatch_at = :rewatch_at_upd, candidates = :candidates_upd
        ';
        $params = array(
            'id_user'                  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_tvtime_import'         => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
            'id_tvtime_import_upd'     => array('value' => $idTvtimeImport, 'type' => PDO::PARAM_INT),
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
            INSERT IGNORE INTO movie_import_pending_list (id_movie_import_pending, id_user_list, added_at)
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
            FROM movie_import_pending_list
            WHERE id_movie_import_pending = :id_pending
        ';
        $params = array('id_pending' => array('value' => $idMovieImportPending, 'type' => PDO::PARAM_INT));
        $rows   = $this->mysql->query($sql, $params);

        return array_map(
            static fn(array $row): array => array('id_user_list' => (int) $row['id_user_list'], 'added_at' => $row['added_at']),
            $rows
        );
    }

    /**
     * how many of $idUserList's own movies are still waiting on a pending
     * row - for the app's own "X of Y imported" list indicator
     */
    public function pendingCountForList(int $idUserList): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM movie_import_pending_list
            WHERE id_user_list = :id_user_list
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $idUser): array
    {
        $sql    = '
            SELECT *
            FROM movie_import_pending
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

    /**
     * see SeriesImportPending::idForShowName()'s own docblock, identical
     * reasoning
     */
    public function idForMovieName(int $idUser, string $movieName): ?int
    {
        $sql    = '
            SELECT id_movie_import_pending
            FROM movie_import_pending
            WHERE id_user = :id_user AND movie_name = :movie_name
            LIMIT 1
        ';
        $params = array(
            'id_user'    => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'movie_name' => array('value' => $movieName, 'type' => PDO::PARAM_STR),
        );
        $rows   = $this->mysql->query($sql, $params);
        return isset($rows[0]) ? (int) $rows[0]['id_movie_import_pending'] : null;
    }

    private function findOwnedByUser(int $id, int $idUser): ?array
    {
        $sql    = '
            SELECT *
            FROM movie_import_pending
            WHERE id_movie_import_pending = :id AND id_user = :id_user
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
     * Applies the user's chosen TheTVDB movie for a pending title - syncs
     * it/them (same lazy-mirror sync() every other movie endpoint uses),
     * then replays the watchlist/watched/rewatch state that was snapshotted
     * at import time, exactly as Processor::processMovies() would have done
     * for a confident match. Removes the pending row once applied.
     *
     * $tvdbIds can have more than one entry - TV Time's own export
     * sometimes has no way to tell two real movies apart under one title
     * (e.g. "Mulan" 1998 vs 2020, both watched), so the same snapshotted
     * state is applied to each chosen id independently rather than forcing
     * a single pick. There's genuinely no way to know from the source data
     * whether a single recorded watch was of one of them or both - this is
     * the user's own call, made with the poster+year in front of them
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

        $linkedLists   = $this->linkedLists((int) $pending['id_movie_import_pending']);
        $userListMovie = new UserListMovie();

        $appliedAny = false;
        foreach ($tvdbIds as $tvdbId) {
            $movie = new Movie();
            $info  = $movie->sync($tvdbId, $client);
            if (empty($info)) {
                // one bad id among several shouldn't block the others
                continue;
            }

            (new MovieWatchlist())->addFromImport($idUser, $info['id_movie'], $pending['watchlist_created_at']);

            if ($pending['watched_at'] !== null) {
                (new WatchedMovie())->markWatched($idUser, $info['id_movie'], $pending['watched_at']);
            }
            foreach (json_decode($pending['rewatch_at'], true) ?? array() as $rewatchAt) {
                (new WatchedMovie())->markRewatched($idUser, $info['id_movie'], $rewatchAt);
            }

            // this movie was also wanted as a member of one or more lists
            // (Processor::processLists() linked it here instead of silently
            // dropping it - see movie_import_pending_list's own docblock) -
            // now that it's actually synced, add it to each of them too
            foreach ($linkedLists as $linked) {
                $userListMovie->add($linked['id_user_list'], (int) $info['id_movie'], $linked['added_at']);
            }

            $appliedAny = true;
        }

        if (!$appliedAny) {
            return false;
        }

        $this->delete((int) $pending['id_movie_import_pending']);
        return true;
    }

    /**
     * Dismisses a pending title without applying anything - the user
     * confirmed none of the candidates (or the lack of any) are right.
     *
     * @return bool false if no such pending row belongs to this user
     */
    public function skip(int $id, int $idUser): bool
    {
        $pending = $this->findOwnedByUser($id, $idUser);
        if ($pending === null) {
            return false;
        }
        $this->delete((int) $pending['id_movie_import_pending']);
        return true;
    }

    private function delete(int $id): void
    {
        // also deletes every movie_import_pending_list row for this pending
        // title - see SeriesImportPending::delete()'s own docblock, same
        // "no FK cascade in this schema" reasoning
        $sql    = '
            DELETE FROM movie_import_pending_list
            WHERE id_movie_import_pending = :id
        ';
        $params = array('id' => array('value' => $id, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);

        $sql = '
            DELETE FROM movie_import_pending
            WHERE id_movie_import_pending = :id
        ';
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM movie_import_pending
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

}

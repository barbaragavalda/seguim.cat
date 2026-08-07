<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/** A user's own custom series lists (e.g. imported from TV Time - see TvTimeImport\Parser). Ordered via a large-gap integer `ordering`, see moveAfter() */
class UserList extends Model
{

    private const int PAGE_SIZE = 20;

    private const int GAP = 1000;

    /** $createdAt preserves TV Time's import date when set; defaults to now otherwise - see Watchlist::addFromImport() */
    public function create(int $idUser, string $name, ?int $ordering = null, ?string $createdAt = null): int
    {
        $sql    = '
            INSERT INTO user_list (id_user, name, ordering, created)
            VALUES (:id_user, :name, :ordering, :created)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'name'     => array('value' => $name, 'type' => PDO::PARAM_STR),
            'ordering' => array('value' => $ordering ?? $this->nextOrdering($idUser), 'type' => PDO::PARAM_INT),
            'created'  => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);

        return (int) $this->mysql->lastInsertId();
    }

    /** Same as create(), but tags the list with TV Time's list key so a later import can find it again via findByTvtimeKey() instead of duplicating it */
    public function createFromImport(int $idUser, string $name, string $tvtimeSKey, ?string $createdAt = null): int
    {
        $sql    = '
            INSERT INTO user_list (id_user, name, tvtime_s_key, ordering, created)
            VALUES (:id_user, :name, :tvtime_s_key, :ordering, :created)
        ';
        $params = array(
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'name'         => array('value' => $name, 'type' => PDO::PARAM_STR),
            'tvtime_s_key' => array('value' => $tvtimeSKey, 'type' => PDO::PARAM_STR),
            'ordering'     => array('value' => $this->nextOrdering($idUser), 'type' => PDO::PARAM_INT),
            'created'      => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);

        return (int) $this->mysql->lastInsertId();
    }

    /**
     * The list a previous import already created for this TV Time key, if any - lets a
     * re-import reuse it. Deliberately doesn't touch the list's `name` on a match, since
     * the user may have renamed it since.
     */
    public function findByTvtimeKey(int $idUser, string $tvtimeSKey): ?array
    {
        $sql    = '
            SELECT *
            FROM user_list
            WHERE id_user = :id_user AND tvtime_s_key = :tvtime_s_key
            LIMIT 1
        ';
        $params = array(
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'tvtime_s_key' => array('value' => $tvtimeSKey, 'type' => PDO::PARAM_STR),
        );
        $rows   = $this->mysql->query($sql, $params);

        return $rows[0] ?? null;
    }

    public function rename(int $idUser, int $idUserList, string $name): void
    {
        $sql    = '
            UPDATE user_list
            SET name = :name
            WHERE id_user_list = :id_user_list AND id_user = :id_user
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'name'         => array('value' => $name, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    public function belongsToUser(int $idUser, int $idUserList): bool
    {
        $sql    = '
            SELECT 1
            FROM user_list
            WHERE id_user_list = :id_user_list AND id_user = :id_user
            LIMIT 1
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /** Also deletes user_list_serie/movie rows and pending links for this list - no FK cascade in this schema, so callers don't need to clean those up separately */
    public function delete(int $idUser, int $idUserList): void
    {
        $sql    = '
            DELETE FROM user_list_serie
            WHERE id_user_list = :id_user_list
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);

        $sql = '
            DELETE FROM user_list_movie
            WHERE id_user_list = :id_user_list
        ';
        $this->mysql->query($sql, $params);

        $sql = '
            DELETE FROM user_serie_list_pending
            WHERE id_user_list = :id_user_list
        ';
        $this->mysql->query($sql, $params);

        $sql = '
            DELETE FROM user_movie_list_pending
            WHERE id_user_list = :id_user_list
        ';
        $this->mysql->query($sql, $params);

        $sql    = '
            DELETE FROM user_list
            WHERE id_user_list = :id_user_list AND id_user = :id_user
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE ul, uls, ulm, sipl, mipl
            FROM user_list ul
            LEFT JOIN user_list_serie uls ON uls.id_user_list = ul.id_user_list
            LEFT JOIN user_list_movie ulm ON ulm.id_user_list = ul.id_user_list
            LEFT JOIN user_serie_list_pending sipl ON sipl.id_user_list = ul.id_user_list
            LEFT JOIN user_movie_list_pending mipl ON mipl.id_user_list = ul.id_user_list
            WHERE ul.id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    /** Unpaginated - for the Lists\Membership picker, which needs the full set to render its checkboxes */
    public function allForUser(int $idUser): array
    {
        $sql    = '
            SELECT *
            FROM user_list
            WHERE id_user = :id_user
            ORDER BY ordering ASC
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        return $this->mysql->query($sql, $params);
    }

    /**
     * @return array{results: array, hasMore: bool}
     */
    public function listForUser(int $idUser, int $page): array
    {
        $sql    = '
            SELECT *
            FROM user_list
            WHERE id_user = :id_user
            ORDER BY ordering ASC
            LIMIT :limit OFFSET :offset
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'limit'   => array('value' => self::PAGE_SIZE + 1, 'type' => PDO::PARAM_INT),
            'offset'  => array('value' => max(0, $page) * self::PAGE_SIZE, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        $hasMore = count($rows) > self::PAGE_SIZE;

        return array('results' => array_slice($rows, 0, self::PAGE_SIZE), 'hasMore' => $hasMore);
    }

    /**
     * Moves $idUserList after $afterIdUserList (or to the front if null). Only needs to
     * know the neighbor, not the whole order - keeps this pagination-safe. Returns false
     * if $afterIdUserList doesn't exist for this user (a stale reference).
     */
    public function moveAfter(int $idUser, int $idUserList, ?int $afterIdUserList): bool
    {
        if ($afterIdUserList === $idUserList) {
            return true;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $newOrdering = $afterIdUserList === null
                ? $this->orderingBeforeFirst($idUser, $idUserList)
                : $this->orderingAfter($idUser, $idUserList, $afterIdUserList);

            if ($newOrdering === false) {
                return false;
            }
            if ($newOrdering !== null) {
                $this->setOrdering($idUserList, $newOrdering);
                return true;
            }

            // null means the neighbors are already adjacent integers - no room between
            // them. Rebalance and try once more.
            $this->rebalance($idUser);
        }

        return true;
    }

    private function orderingBeforeFirst(int $idUser, int $idUserList): ?int
    {
        $sql    = '
            SELECT MIN(ordering) AS ordering
            FROM user_list
            WHERE id_user = :id_user AND id_user_list != :id_user_list
        ';
        $params = array(
            'id_user'      => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
        );
        $min    = $this->mysql->query($sql, $params)[0]['ordering'] ?? null;
        if ($min === null) {
            return self::GAP;
        }

        $candidate = ((int) $min) - self::GAP;
        return $candidate < (int) $min ? $candidate : null;
    }

    /**
     * @return int|false|null new ordering value, or null if there's no room
     *                        (needs a rebalance), or false if $afterIdUserList
     *                        isn't a real list of this user's
     */
    private function orderingAfter(int $idUser, int $idUserList, int $afterIdUserList): int|false|null
    {
        $sql    = '
            SELECT ordering
            FROM user_list
            WHERE id_user = :id_user AND id_user_list = :after_id
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'after_id' => array('value' => $afterIdUserList, 'type' => PDO::PARAM_INT),
        );
        $result = $this->mysql->query($sql, $params);
        if (count($result) === 0) {
            return false;
        }
        $afterOrdering = (int) $result[0]['ordering'];

        $sql    = '
            SELECT MIN(ordering) AS ordering
            FROM user_list
            WHERE id_user = :id_user AND ordering > :after_ordering AND id_user_list != :id_user_list
        ';
        $params = array(
            'id_user'        => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'after_ordering' => array('value' => $afterOrdering, 'type' => PDO::PARAM_INT),
            'id_user_list'   => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
        );
        $next   = $this->mysql->query($sql, $params)[0]['ordering'] ?? null;

        if ($next === null) {
            return $afterOrdering + self::GAP;
        }

        $midpoint = intdiv($afterOrdering + (int) $next, 2);
        return $midpoint > $afterOrdering && $midpoint < (int) $next ? $midpoint : null;
    }

    private function setOrdering(int $idUserList, int $ordering): void
    {
        $sql    = '
            UPDATE user_list
            SET ordering = :ordering
            WHERE id_user_list = :id_user_list
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'ordering'     => array('value' => $ordering, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function rebalance(int $idUser): void
    {
        $sql    = '
            SELECT id_user_list
            FROM user_list
            WHERE id_user = :id_user
            ORDER BY ordering ASC
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $ids    = array_column($this->mysql->query($sql, $params), 'id_user_list');

        foreach ($ids as $index => $id) {
            $this->setOrdering((int) $id, ($index + 1) * self::GAP);
        }
    }

    private function nextOrdering(int $idUser): int
    {
        $sql    = '
            SELECT MAX(ordering) AS ordering
            FROM user_list
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $max    = $this->mysql->query($sql, $params)[0]['ordering'] ?? null;

        return $max === null ? self::GAP : ((int) $max) + self::GAP;
    }

}

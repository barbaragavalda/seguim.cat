<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * A user's own custom series lists (e.g. imported from TV Time's own
 * "lists" feature - see Api\Model\TvTimeImport\Parser). Ordered among
 * themselves via a large-gap integer `ordering` (1000 per slot) rather
 * than dense positions - see moveAfter()'s own docblock for why.
 */
class UserList extends Model
{

    private const int PAGE_SIZE = 20;

    private const int GAP = 1000;

    /**
     * $createdAt preserves TV Time's own list-creation date when called
     * from the importer (Api\Model\TvTimeImport\Processor) - defaults to
     * "now" for the regular Lists\Create controller flow, same reasoning
     * as Watchlist::addFromImport()'s own $createdAt
     */
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

    /**
     * also deletes every user_list_serie row for this list - there's no FK
     * cascade in this schema (none of this project's tables use one), so
     * the caller doesn't have to remember to call UserListSerie separately
     */
    public function delete(int $idUser, int $idUserList): void
    {
        $sql    = '
            DELETE FROM user_list_serie
            WHERE id_user_list = :id_user_list
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
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
            DELETE ul, uls
            FROM user_list ul
            LEFT JOIN user_list_serie uls ON uls.id_user_list = ul.id_user_list
            WHERE ul.id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    /**
     * every list of $idUser's, unpaginated - for the "which of my lists is
     * this series already in" picker (Lists\Membership), which needs the
     * full set to render its checkboxes rather than one page at a time
     */
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
     * moves $idUserList to be right after $afterIdUserList among this
     * user's own lists, or to the very front if $afterIdUserList is null.
     * Deliberately doesn't need to know the *whole* order - only the
     * neighbor a client dropped this list next to, which is always on
     * whatever page it's currently looking at, unlike a "send the full
     * order" design that would need every list loaded across every page
     * at once. Returns false if $afterIdUserList doesn't exist for this
     * user (a stale reference - e.g. that list got deleted by another
     * request first)
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

            // null means the two neighbors are already adjacent integers -
            // no room to fit a new value between them. Renumber the whole
            // list generously and try exactly once more
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

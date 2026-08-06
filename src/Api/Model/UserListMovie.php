<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * The movies inside one specific user list (Api\Model\UserList) - exact
 * mirror of UserListSerie, own independent ordering from that list's own
 * series (same large-gap integer scheme - see UserList::moveAfter()'s own
 * docblock for why).
 */
class UserListMovie extends Model
{

    private const int PAGE_SIZE = 20;

    private const int GAP = 1000;

    /**
     * $createdAt preserves TV Time's own "added to list" date when called
     * from the importer (Api\Model\TvTimeImport\Processor) - defaults to
     * "now" for the regular Lists\AddMovie controller flow, same reasoning
     * as UserListSerie::add()'s own $createdAt
     */
    public function add(int $idUserList, int $idMovie, ?string $createdAt = null): void
    {
        if ($this->has($idUserList, $idMovie)) {
            return;
        }

        $sql    = '
            INSERT INTO user_list_movie (id_user_list, id_movie, ordering, created)
            VALUES (:id_user_list, :id_movie, :ordering, :created)
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_movie'     => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'ordering'     => array('value' => $this->nextOrdering($idUserList), 'type' => PDO::PARAM_INT),
            'created'      => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    public function remove(int $idUserList, int $idMovie): void
    {
        $sql    = '
            DELETE FROM user_list_movie
            WHERE id_user_list = :id_user_list AND id_movie = :id_movie
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_movie'     => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUserList, int $idMovie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_list_movie
            WHERE id_user_list = :id_user_list AND id_movie = :id_movie
            LIMIT 1
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_movie'     => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /**
     * which of $idUser's own lists already contain $idMovie - for the
     * "add to a list" picker (Lists\MembershipMovie). Scoped through
     * user_list's own id_user, same ownership-check reasoning as
     * UserListSerie::listIdsContainingSerie()
     *
     * @return int[] id_user_list values
     */
    public function listIdsContainingMovie(int $idUser, int $idMovie): array
    {
        $sql    = '
            SELECT ulm.id_user_list
            FROM user_list_movie ulm
            INNER JOIN user_list ul ON ul.id_user_list = ulm.id_user_list
            WHERE ul.id_user = :id_user AND ulm.id_movie = :id_movie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        return array_map('intval', array_column($this->mysql->query($sql, $params), 'id_user_list'));
    }

    /**
     * @return array{results: array, hasMore: bool}
     */
    public function listForList(int $idUserList, int $page): array
    {
        $sql    = '
            SELECT m.*
            FROM user_list_movie ulm
            INNER JOIN movie m ON m.id_movie = ulm.id_movie
            WHERE ulm.id_user_list = :id_user_list
            ORDER BY ulm.ordering ASC
            LIMIT :limit OFFSET :offset
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'limit'        => array('value' => self::PAGE_SIZE + 1, 'type' => PDO::PARAM_INT),
            'offset'       => array('value' => max(0, $page) * self::PAGE_SIZE, 'type' => PDO::PARAM_INT),
        );
        $rows    = $this->mysql->query($sql, $params);
        $hasMore = count($rows) > self::PAGE_SIZE;

        return array('results' => array_slice($rows, 0, self::PAGE_SIZE), 'hasMore' => $hasMore);
    }

    public function countForList(int $idUserList): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM user_list_movie
            WHERE id_user_list = :id_user_list
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
    }

    /**
     * the first $limit movies of this list, in its own manual order - used
     * by Lists\Index to top up its poster-thumbnail preview once a list's
     * own series (UserListSerie::previewForList(), tried first) don't fill
     * it on their own. Not merged into one single by-date-added query
     * across both tables: series and movies keep fully independent manual
     * orderings (drag-and-drop reordering, own `ordering` column each), so
     * there's no one shared "position" to sort a combined preview by
     * without picking a different, disconnected-from-the-drag-handle
     * ordering the user never actually set
     *
     * @return array<int, array{tvdb_id: int, image: ?string}>
     */
    public function previewForList(int $idUserList, int $limit): array
    {
        $sql    = '
            SELECT m.tvdb_id, m.image
            FROM user_list_movie ulm
            INNER JOIN movie m ON m.id_movie = ulm.id_movie
            WHERE ulm.id_user_list = :id_user_list
            ORDER BY ulm.ordering ASC
            LIMIT :limit
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'limit'        => array('value' => $limit, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    /**
     * moves $idMovie to be right after $afterIdMovie within $idUserList, or
     * to the front if $afterIdMovie is null - same pagination-safe
     * reasoning as UserListSerie::moveAfter(). Returns false if
     * $afterIdMovie isn't actually in this list
     */
    public function moveAfter(int $idUserList, int $idMovie, ?int $afterIdMovie): bool
    {
        if ($afterIdMovie === $idMovie) {
            return true;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $newOrdering = $afterIdMovie === null
                ? $this->orderingBeforeFirst($idUserList, $idMovie)
                : $this->orderingAfter($idUserList, $idMovie, $afterIdMovie);

            if ($newOrdering === false) {
                return false;
            }
            if ($newOrdering !== null) {
                $this->setOrdering($idUserList, $idMovie, $newOrdering);
                return true;
            }

            $this->rebalance($idUserList);
        }

        return true;
    }

    private function orderingBeforeFirst(int $idUserList, int $idMovie): ?int
    {
        $sql    = '
            SELECT MIN(ordering) AS ordering
            FROM user_list_movie
            WHERE id_user_list = :id_user_list AND id_movie != :id_movie
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_movie'     => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $min    = $this->mysql->query($sql, $params)[0]['ordering'] ?? null;
        if ($min === null) {
            return self::GAP;
        }

        $candidate = ((int) $min) - self::GAP;
        return $candidate < (int) $min ? $candidate : null;
    }

    /**
     * @return int|false|null new ordering value, null if there's no room
     *                        (needs a rebalance), false if $afterIdMovie
     *                        isn't in this list
     */
    private function orderingAfter(int $idUserList, int $idMovie, int $afterIdMovie): int|false|null
    {
        $sql    = '
            SELECT ordering
            FROM user_list_movie
            WHERE id_user_list = :id_user_list AND id_movie = :after_id
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'after_id'     => array('value' => $afterIdMovie, 'type' => PDO::PARAM_INT),
        );
        $result = $this->mysql->query($sql, $params);
        if (count($result) === 0) {
            return false;
        }
        $afterOrdering = (int) $result[0]['ordering'];

        $sql    = '
            SELECT MIN(ordering) AS ordering
            FROM user_list_movie
            WHERE id_user_list = :id_user_list AND ordering > :after_ordering AND id_movie != :id_movie
        ';
        $params = array(
            'id_user_list'   => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'after_ordering' => array('value' => $afterOrdering, 'type' => PDO::PARAM_INT),
            'id_movie'       => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $next   = $this->mysql->query($sql, $params)[0]['ordering'] ?? null;

        if ($next === null) {
            return $afterOrdering + self::GAP;
        }

        $midpoint = intdiv($afterOrdering + (int) $next, 2);
        return $midpoint > $afterOrdering && $midpoint < (int) $next ? $midpoint : null;
    }

    private function setOrdering(int $idUserList, int $idMovie, int $ordering): void
    {
        $sql    = '
            UPDATE user_list_movie
            SET ordering = :ordering
            WHERE id_user_list = :id_user_list AND id_movie = :id_movie
        ';
        $params = array(
            'id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT),
            'id_movie'     => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'ordering'     => array('value' => $ordering, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function rebalance(int $idUserList): void
    {
        $sql    = '
            SELECT id_movie
            FROM user_list_movie
            WHERE id_user_list = :id_user_list
            ORDER BY ordering ASC
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        $ids    = array_column($this->mysql->query($sql, $params), 'id_movie');

        foreach ($ids as $index => $idMovie) {
            $this->setOrdering($idUserList, (int) $idMovie, ($index + 1) * self::GAP);
        }
    }

    private function nextOrdering(int $idUserList): int
    {
        $sql    = '
            SELECT MAX(ordering) AS ordering
            FROM user_list_movie
            WHERE id_user_list = :id_user_list
        ';
        $params = array('id_user_list' => array('value' => $idUserList, 'type' => PDO::PARAM_INT));
        $max    = $this->mysql->query($sql, $params)[0]['ordering'] ?? null;

        return $max === null ? self::GAP : ((int) $max) + self::GAP;
    }

}

<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/** A user's favorite movies - independent of user_movie_watchlist membership. */
class MovieFavorite extends Model
{

    private const int PAGE_SIZE = 20;

    public function add(int $idUser, int $idMovie): void
    {
        $sql    = '
            INSERT IGNORE INTO user_movie_favorite (id_user, id_movie)
            VALUES (:id_user, :id_movie)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /** Preserves the import's own favorited-at date instead of "now". */
    public function addFromImport(int $idUser, int $idMovie, ?string $createdAt = null): void
    {
        $sql    = '
            INSERT IGNORE INTO user_movie_favorite (id_user, id_movie, created)
            VALUES (:id_user, :id_movie, :created)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'created'  => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    public function remove(int $idUser, int $idMovie): void
    {
        $sql    = '
            DELETE FROM user_movie_favorite
            WHERE id_user = :id_user AND id_movie = :id_movie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_movie_favorite
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUser, int $idMovie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_movie_favorite
            WHERE id_user = :id_user AND id_movie = :id_movie
            LIMIT 1
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    public function countForUser(int $idUser): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM user_movie_favorite
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
    }

    /** @return array<int, array{tvdb_id: int, image: ?string}> */
    public function previewForUser(int $idUser, int $limit): array
    {
        $sql    = '
            SELECT m.tvdb_id, m.image
            FROM user_movie_favorite f
            INNER JOIN movie m ON m.id_movie = f.id_movie
            WHERE f.id_user = :id_user
            ORDER BY f.created DESC
            LIMIT :limit
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'limit'   => array('value' => $limit, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

    /**
     * Un-enriched `m.*`, no per-language translation.
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listForUser(int $idUser, int $page): array
    {
        $sql    = '
            SELECT m.*
            FROM user_movie_favorite f
            INNER JOIN movie m ON m.id_movie = f.id_movie
            WHERE f.id_user = :id_user
            ORDER BY f.created DESC
            LIMIT :limit OFFSET :offset
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'limit'   => array('value' => self::PAGE_SIZE + 1, 'type' => PDO::PARAM_INT),
            'offset'  => array('value' => max(0, $page) * self::PAGE_SIZE, 'type' => PDO::PARAM_INT),
        );
        $rows    = $this->mysql->query($sql, $params);
        $hasMore = count($rows) > self::PAGE_SIZE;

        return array('results' => array_slice($rows, 0, self::PAGE_SIZE), 'hasMore' => $hasMore);
    }

}

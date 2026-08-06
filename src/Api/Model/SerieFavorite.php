<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * A user's favorite series - see MovieFavorite's own docblock, identical
 * shape and reasoning (independent of user_serie_watchlist membership,
 * replaces TV Time's "Favorite Shows" export list).
 */
class SerieFavorite extends Model
{

    private const int PAGE_SIZE = 20;

    public function add(int $idUser, int $idSerie): void
    {
        $sql    = '
            INSERT IGNORE INTO user_serie_favorite (id_user, id_serie)
            VALUES (:id_user, :id_serie)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * used only by the TV Time importer - preserves TV Time's own
     * favorited-at date rather than "now", same reasoning as
     * Watchlist::addFromImport()
     */
    public function addFromImport(int $idUser, int $idSerie, ?string $createdAt = null): void
    {
        $sql    = '
            INSERT IGNORE INTO user_serie_favorite (id_user, id_serie, created)
            VALUES (:id_user, :id_serie, :created)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'created'  => array('value' => $createdAt ?? date('Y-m-d H:i:s'), 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    public function remove(int $idUser, int $idSerie): void
    {
        $sql    = '
            DELETE FROM user_serie_favorite
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
            DELETE FROM user_serie_favorite
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUser, int $idSerie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_serie_favorite
            WHERE id_user = :id_user AND id_serie = :id_serie
            LIMIT 1
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    public function countForUser(int $idUser): int
    {
        $sql    = '
            SELECT COUNT(*) AS cnt
            FROM user_serie_favorite
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        return (int) ($this->mysql->query($sql, $params)[0]['cnt'] ?? 0);
    }

    /**
     * the most recently favorited $limit series - see MovieFavorite::
     * previewForUser()'s own docblock, identical reasoning
     *
     * @return array<int, array{tvdb_id: int, image: ?string}>
     */
    public function previewForUser(int $idUser, int $limit): array
    {
        $sql    = '
            SELECT s.tvdb_id, s.image
            FROM user_serie_favorite f
            INNER JOIN serie s ON s.id_serie = f.id_serie
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
     * paginated, most recently favorited first - backs FavoritesDetailScreen
     * (Favorites\Series), watch progress attached by the controller same as
     * Lists\Show::withWatchProgress(). Un-enriched `s.*`, no per-language
     * translation - same convention as UserListSerie::listForList() (the
     * regular list-detail grid), which this mirrors
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listForUser(int $idUser, int $page): array
    {
        $sql    = '
            SELECT s.*
            FROM user_serie_favorite f
            INNER JOIN serie s ON s.id_serie = f.id_serie
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

<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class MovieWatchlist extends Model
{

    private const int PAGE_SIZE = 20;

    private const array STATUSES = array('all', 'not_watched', 'watched');

    public function add(int $idUser, int $idMovie): void
    {
        $sql    = '
            INSERT IGNORE INTO user_movie_watchlist (id_user, id_movie)
            VALUES (:id_user, :id_movie)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    /**
     * used only by the TV Time importer (Api\Model\TvTimeImport\Processor) -
     * preserves TV Time's own follow/to-watch date rather than "now", same
     * reasoning as Watchlist::addFromImport(). Unlike that one, there are no
     * archived/removed flags to keep in sync on a re-run - a plain INSERT
     * IGNORE is enough, an existing row (and whatever created date it
     * already has) is simply left alone
     */
    public function addFromImport(int $idUser, int $idMovie, ?string $createdAt = null): void
    {
        $sql    = '
            INSERT IGNORE INTO user_movie_watchlist (id_user, id_movie, created)
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
            DELETE FROM user_movie_watchlist
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
            DELETE FROM user_movie_watchlist
            WHERE id_user = :id_user
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUser, int $idMovie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_movie_watchlist
            WHERE id_user = :id_user AND id_movie = :id_movie
            LIMIT 1
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /**
     * unified, paginated "browse everything" view (profile "Les meves
     * pel·lícules" + the Pel·lícules tab) - counterpart of Watchlist::
     * listByStatus(), simplified: a movie has no episodes, so there's no
     * "watching" partial state - just watched or not, same binary the
     * rewatch feature already tracks. $search behaves the same as
     * Watchlist::listByStatus() (LIKE on the translated name, falling back
     * to default_name)
     *
     * @return array{results: array, hasMore: bool}
     */
    public function listByStatus(int $idUser, int $idAppacmanLang, string $status, int $page, ?string $search = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown movie watchlist status: ' . $status);
        }

        $hasWatched = '
            EXISTS (
                SELECT 1
                FROM user_movie_watched umw
                WHERE umw.id_user = w.id_user AND umw.id_movie = m.id_movie
            )
        ';
        $searchCondition = $search !== null && $search !== ''
            ? ' AND COALESCE(ml.name, m.default_name) LIKE :search '
            : '';

        $sql = match ($status) {
            'all'         => '
                SELECT m.*, ml.name, ml.overview
                FROM user_movie_watchlist w
                INNER JOIN movie m ON m.id_movie = w.id_movie
                LEFT JOIN movie_lang ml ON ml.id_movie = m.id_movie AND ml.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user' . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'not_watched' => '
                SELECT m.*, ml.name, ml.overview
                FROM user_movie_watchlist w
                INNER JOIN movie m ON m.id_movie = w.id_movie
                LEFT JOIN movie_lang ml ON ml.id_movie = m.id_movie AND ml.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND NOT ' . $hasWatched . $searchCondition . '
                ORDER BY w.created DESC
                LIMIT :limit OFFSET :offset
            ',
            'watched'     => '
                SELECT m.*, ml.name, ml.overview
                FROM user_movie_watchlist w
                INNER JOIN movie m ON m.id_movie = w.id_movie
                LEFT JOIN movie_lang ml ON ml.id_movie = m.id_movie AND ml.id_appacman_lang = :id_appacman_lang
                WHERE w.id_user = :id_user AND ' . $hasWatched . $searchCondition . '
                ORDER BY (
                    SELECT MAX(umw2.watched_at) FROM user_movie_watched umw2
                    WHERE umw2.id_user = w.id_user AND umw2.id_movie = m.id_movie
                ) DESC
                LIMIT :limit OFFSET :offset
            ',
        };

        $params = $this->pageParams($idUser, $idAppacmanLang, $page);
        if ($searchCondition !== '') {
            $params['search'] = array('value' => '%' . $search . '%', 'type' => PDO::PARAM_STR);
        }

        $rows = $this->mysql->query($sql, $params);

        return $this->finalizePage($rows, $idUser);
    }

    private function pageParams(int $idUser, int $idAppacmanLang, int $page): array
    {
        return array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
            'limit'            => array('value' => self::PAGE_SIZE + 1, 'type' => PDO::PARAM_INT),
            'offset'           => array('value' => max(0, $page) * self::PAGE_SIZE, 'type' => PDO::PARAM_INT),
        );
    }

    /**
     * @return array{results: array, hasMore: bool}
     */
    private function finalizePage(array $rows, int $idUser): array
    {
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);

        return array('results' => $this->finalizeRows($rows, $idUser), 'hasMore' => $hasMore);
    }

    private function finalizeRows(array $rows, int $idUser): array
    {
        $watchedMovie = new WatchedMovie();

        foreach ($rows as &$row) {
            $row['name']     = $row['name'] ?: $row['default_name'];
            $row['overview'] = $row['overview'] ?: $row['default_overview'];

            // same convention as Watchlist::finalizeRows() - a list view
            // only ever shows the background/fanart, never the poster
            $row['image'] = $row['background'];
            unset($row['background']);

            $watchCount        = $watchedMovie->watchCount($idUser, (int) $row['id_movie']);
            $row['watched']    = $watchCount > 0;
            $row['watch_count'] = $watchCount;
        }
        unset($row);

        return $rows;
    }

}

<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class Watchlist extends Model
{

    public function add(int $idUser, int $idSeries): void
    {
        $sql    = '
            INSERT IGNORE INTO user_watchlist (id_user, id_series)
            VALUES (:id_user, :id_series)
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_series' => array('value' => $idSeries, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function remove(int $idUser, int $idSeries): void
    {
        $sql    = '
            DELETE FROM user_watchlist
            WHERE id_user = :id_user AND id_series = :id_series
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_series' => array('value' => $idSeries, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUser, int $idSeries): bool
    {
        $sql    = '
            SELECT 1
            FROM user_watchlist
            WHERE id_user = :id_user AND id_series = :id_series
            LIMIT 1
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_series' => array('value' => $idSeries, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    public function listForUser(int $idUser): array
    {
        $sql    = '
            SELECT s.*
            FROM user_watchlist w
            INNER JOIN series s ON s.id_series = w.id_series
            WHERE w.id_user = :id_user
            ORDER BY w.created DESC
        ';
        $params = array(
            'id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT),
        );
        return $this->mysql->query($sql, $params);
    }

}

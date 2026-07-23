<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

class Watchlist extends Model
{

    public function add(int $idUser, int $idSerie): void
    {
        $sql    = '
            INSERT IGNORE INTO user_watchlist (id_user, id_serie)
            VALUES (:id_user, :id_serie)
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function remove(int $idUser, int $idSerie): void
    {
        $sql    = '
            DELETE FROM user_watchlist
            WHERE id_user = :id_user AND id_serie = :id_serie
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    public function has(int $idUser, int $idSerie): bool
    {
        $sql    = '
            SELECT 1
            FROM user_watchlist
            WHERE id_user = :id_user AND id_serie = :id_serie
            LIMIT 1
        ';
        $params = array(
            'id_user'  => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        return count($this->mysql->query($sql, $params)) > 0;
    }

    /**
     * $idAppacmanLang is the current request's already-resolved language
     * (Api\Model\TheTvdb\Languages::idForCulture(Config::getLanguage())) - a
     * LEFT JOIN, not INNER, so a series still shows up even if that language's
     * translation hasn't been synced yet (sl.name/sl.overview just come back
     * null, same as Series/Detail's fallback: sl.name ?: s.default_name)
     */
    public function listForUser(int $idUser, int $idAppacmanLang): array
    {
        $sql    = '
            SELECT s.*, sl.name, sl.overview
            FROM user_watchlist w
            INNER JOIN serie s ON s.id_serie = w.id_serie
            LEFT JOIN serie_lang sl ON sl.id_serie = s.id_serie AND sl.id_appacman_lang = :id_appacman_lang
            WHERE w.id_user = :id_user
            ORDER BY w.created DESC
        ';
        $params = array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        foreach ($rows as &$row) {
            $row['name']     = $row['name'] ?: $row['default_name'];
            $row['overview'] = $row['overview'] ?: $row['default_overview'];
        }
        unset($row);

        return $rows;
    }

}

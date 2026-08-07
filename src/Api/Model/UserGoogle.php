<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * Links a `user` row to its Google account. Kept as its own table (not a
 * column on `user`, which belongs to the shared Webservice\Model\User) so
 * this stays entirely inside tv-tracker-local.
 */
class UserGoogle extends Model
{

    public function link(int $idUser, string $googleId): void
    {
        $sql    = '
            INSERT INTO user_google (id_user, google_id)
            VALUES (:id_user, :google_id)
        ';
        $params = array(
            'id_user'   => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'google_id' => array('value' => $googleId, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

    public function findUserId(string $googleId): ?int
    {
        $sql    = '
            SELECT id_user
            FROM user_google
            WHERE google_id = :google_id
        ';
        $params = array('google_id' => array('value' => $googleId, 'type' => PDO::PARAM_STR));
        $rows   = $this->mysql->query($sql, $params);
        return isset($rows[0]) ? (int) $rows[0]['id_user'] : null;
    }

    public function removeAllForUser(int $idUser): void
    {
        $sql    = '
            DELETE FROM user_google
            WHERE id_user = :id_user
        ';
        $params = array('id_user' => array('value' => $idUser, 'type' => PDO::PARAM_INT));
        $this->mysql->query($sql, $params);
    }

}

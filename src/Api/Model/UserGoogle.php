<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * Links a `user` row to the Google account it signed in with - see
 * db.sql's own docblock on `user_google` for the schema reasoning. Kept as
 * its own table (rather than a column on `user`, which belongs to the
 * shared Webservice\Model\User) so this Google-specific concern stays
 * entirely inside tv-tracker-local, same pattern as SerieFavorite/
 * MovieFavorite.
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

    /**
     * the id_user linked to $googleId, or null if this Google account has
     * never signed in here before
     */
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

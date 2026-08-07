<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * Unlike name/overview, TheTVDB returns a flat list of trailers each
 * already tagged with its own `language` - nothing to sync into a *_lang
 * table, bestForLanguage() just picks among the rows already stored.
 */
class MovieTrailer extends Model
{

    private const string FALLBACK_LANGUAGE = 'eng';

    public function syncForMovie(int $idMovie, array $trailers): void
    {
        $this->deleteForMovie($idMovie);
        foreach ($trailers as $trailer) {
            $this->insert($idMovie, $trailer);
        }
    }

    /**
     * prefers $tvdbLanguageCode, then English, then whatever's first -
     * a movie usually has several dubbed/subtitled trailers but not
     * necessarily one in the app's own language
     */
    public function bestForLanguage(int $idMovie, ?string $tvdbLanguageCode): ?array
    {
        $sql    = '
            SELECT name, url, language
            FROM movie_trailer
            WHERE id_movie = :id_movie
            ORDER BY
                (language = :language) DESC,
                (language = :fallback_language) DESC,
                id_movie_trailer
            LIMIT 1
        ';
        $params = array(
            'id_movie'         => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'language'         => array('value' => $tvdbLanguageCode ?? '', 'type' => PDO::PARAM_STR),
            'fallback_language' => array('value' => self::FALLBACK_LANGUAGE, 'type' => PDO::PARAM_STR),
        );
        $rows = $this->mysql->query($sql, $params);
        return $rows[0] ?? null;
    }

    private function deleteForMovie(int $idMovie): void
    {
        $sql    = 'DELETE FROM movie_trailer WHERE id_movie = :id_movie';
        $params = array(
            'id_movie' => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function insert(int $idMovie, array $trailer): void
    {
        if (empty($trailer['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO movie_trailer (id_movie, tvdb_trailer_id, name, url, language)
            VALUES (:id_movie, :tvdb_trailer_id, :name, :url, :language)
        ';
        $params = array(
            'id_movie'        => array('value' => $idMovie, 'type' => PDO::PARAM_INT),
            'tvdb_trailer_id' => array('value' => $trailer['id'], 'type' => PDO::PARAM_INT),
            'name'            => array('value' => $trailer['name'] ?? null, 'type' => PDO::PARAM_STR),
            'url'             => array('value' => $trailer['url'] ?? null, 'type' => PDO::PARAM_STR),
            'language'        => array('value' => $trailer['language'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

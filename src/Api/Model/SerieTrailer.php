<?php

namespace Api\Model;

use Core\Model\Model;
use PDO;

/**
 * counterpart of Api\Model\MovieTrailer - same reasoning throughout (a flat
 * list of separate trailer entries, each already tagged with its own
 * `language`, not one trailer translated N ways)
 */
class SerieTrailer extends Model
{

    private const string FALLBACK_LANGUAGE = 'eng';

    public function syncForSerie(int $idSerie, array $trailers): void
    {
        $this->deleteForSerie($idSerie);
        foreach ($trailers as $trailer) {
            $this->insert($idSerie, $trailer);
        }
    }

    /**
     * prefers $tvdbLanguageCode, then English, then whatever's first - same
     * as MovieTrailer::bestForLanguage()
     */
    public function bestForLanguage(int $idSerie, ?string $tvdbLanguageCode): ?array
    {
        $sql    = '
            SELECT name, url, language
            FROM serie_trailer
            WHERE id_serie = :id_serie
            ORDER BY
                (language = :language) DESC,
                (language = :fallback_language) DESC,
                id_serie_trailer
            LIMIT 1
        ';
        $params = array(
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'language'         => array('value' => $tvdbLanguageCode ?? '', 'type' => PDO::PARAM_STR),
            'fallback_language' => array('value' => self::FALLBACK_LANGUAGE, 'type' => PDO::PARAM_STR),
        );
        $rows = $this->mysql->query($sql, $params);
        return $rows[0] ?? null;
    }

    private function deleteForSerie(int $idSerie): void
    {
        $sql    = 'DELETE FROM serie_trailer WHERE id_serie = :id_serie';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $this->mysql->query($sql, $params);
    }

    private function insert(int $idSerie, array $trailer): void
    {
        if (empty($trailer['id'])) {
            return;
        }
        $sql    = '
            INSERT INTO serie_trailer (id_serie, tvdb_trailer_id, name, url, language)
            VALUES (:id_serie, :tvdb_trailer_id, :name, :url, :language)
        ';
        $params = array(
            'id_serie'        => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'tvdb_trailer_id' => array('value' => $trailer['id'], 'type' => PDO::PARAM_INT),
            'name'            => array('value' => $trailer['name'] ?? null, 'type' => PDO::PARAM_STR),
            'url'             => array('value' => $trailer['url'] ?? null, 'type' => PDO::PARAM_STR),
            'language'        => array('value' => $trailer['language'] ?? null, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

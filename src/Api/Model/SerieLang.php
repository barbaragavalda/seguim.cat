<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

class SerieLang extends Model
{

    private const int TTL_SECONDS = 86400; // 24h

    /**
     * id_appacman_lang (this project's existing language lookup table,
     * config/projects.php's api sub-project languages - 1=ca, 2=es, 3=en)
     * -> TheTVDB's own 3-letter language code, for GET /series/{id}/
     * translations/{language}
     */
    private const array TVDB_LANGUAGE = array(
        1 => 'cat',
        2 => 'spa',
        3 => 'eng',
    );

    private const array CULTURE_TO_ID = array(
        'ca' => 1,
        'es' => 2,
        'en' => 3,
    );

    /**
     * id_appacman_lang for a culture code (e.g. Core\Utils\Config::getLanguage(),
     * already resolved per-request from Accept-Language/session/URL - see
     * Core\Utils\Language::initLanguage()), or null for an unsupported one
     */
    public static function idForCulture(string $culture): ?int
    {
        return self::CULTURE_TO_ID[$culture] ?? null;
    }

    /**
     * fetches/refreshes just the requested language's translation if it's
     * missing or stale, then returns it - deliberately not all of this
     * app's languages at once, since a given request only ever needs its
     * own resolved one. Returns ['name' => null, 'overview' => null] both
     * for "TheTVDB has no translation in this language" and for an
     * unsupported $culture, rather than distinguishing the two - the caller
     * doesn't need to
     */
    public function syncForLanguage(int $idSerie, int $tvdbSerieId, string $culture, Client $client): array
    {
        $idAppacmanLang = self::idForCulture($culture);
        if ($idAppacmanLang === null) {
            return array('name' => null, 'overview' => null);
        }

        $row = $this->find($idSerie, $idAppacmanLang);
        if ($row === null || $this->isStale($row['synced_at'])) {
            $translation = $client->getSeriesTranslation($tvdbSerieId, self::TVDB_LANGUAGE[$idAppacmanLang]);
            $this->upsert($idSerie, $idAppacmanLang, $translation);
            $row = $this->find($idSerie, $idAppacmanLang);
        }

        return array('name' => $row['name'] ?? null, 'overview' => $row['overview'] ?? null);
    }

    private function find(int $idSerie, int $idAppacmanLang): ?array
    {
        $sql    = '
            SELECT *
            FROM serie_lang
            WHERE id_serie = :id_serie AND id_appacman_lang = :id_appacman_lang
        ';
        $params = array(
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);
        return $rows[0] ?? null;
    }

    private function isStale(string $syncedAt): bool
    {
        return strtotime($syncedAt) <= (time() - self::TTL_SECONDS);
    }

    private function upsert(int $idSerie, int $idAppacmanLang, ?array $translation): void
    {
        $sql    = '
            INSERT INTO serie_lang (id_serie, id_appacman_lang, name, overview, synced_at)
            VALUES (:id_serie, :id_appacman_lang, :name, :overview, NOW())
            ON DUPLICATE KEY UPDATE
                name = :name_upd, overview = :overview_upd, synced_at = NOW()
        ';
        $name     = $translation['name'] ?? null;
        $overview = $translation['overview'] ?? null;

        $params = array(
            'id_serie'         => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
            'name'             => array('value' => $name, 'type' => PDO::PARAM_STR),
            'name_upd'         => array('value' => $name, 'type' => PDO::PARAM_STR),
            'overview'         => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'overview_upd'     => array('value' => $overview, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

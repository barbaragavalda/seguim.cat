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
     * -> [culture (the key used in the API response), tvdb (TheTVDB's own
     * 3-letter language code for GET /series/{id}/translations/{language})]
     */
    private const array LANGUAGES = array(
        1 => array('culture' => 'ca', 'tvdb' => 'cat'),
        2 => array('culture' => 'es', 'tvdb' => 'spa'),
        3 => array('culture' => 'en', 'tvdb' => 'eng'),
    );

    /**
     * fetches/refreshes whichever of this project's languages are missing
     * or stale, then returns every language's translation keyed by culture
     * (ca/es/en) - a language with no TheTVDB translation available comes
     * back as ['name' => null, 'overview' => null], not simply absent
     */
    public function syncForSerie(int $idSerie, int $tvdbSerieId, Client $client): array
    {
        $existing = $this->allForSerie($idSerie); // keyed by id_appacman_lang

        foreach (self::LANGUAGES as $idAppacmanLang => $language) {
            $row = $existing[$idAppacmanLang] ?? null;
            if ($row !== null && !$this->isStale($row['synced_at'])) {
                continue;
            }

            $translation = $client->getSeriesTranslation($tvdbSerieId, $language['tvdb']);
            $this->upsert($idSerie, $idAppacmanLang, $translation);
        }

        $rows      = $this->allForSerie($idSerie);
        $byCulture = array();
        foreach (self::LANGUAGES as $idAppacmanLang => $language) {
            $row                            = $rows[$idAppacmanLang] ?? null;
            $byCulture[$language['culture']] = array(
                'name'     => $row['name'] ?? null,
                'overview' => $row['overview'] ?? null,
            );
        }
        return $byCulture;
    }

    /**
     * @return array<int, array> existing rows keyed by id_appacman_lang
     */
    private function allForSerie(int $idSerie): array
    {
        $sql    = '
            SELECT *
            FROM serie_lang
            WHERE id_serie = :id_serie
        ';
        $params = array(
            'id_serie' => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
        );
        $rows   = $this->mysql->query($sql, $params);

        $keyed = array();
        foreach ($rows as $row) {
            $keyed[$row['id_appacman_lang']] = $row;
        }
        return $keyed;
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

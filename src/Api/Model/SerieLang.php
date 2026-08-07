<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Api\Model\TheTvdb\Languages;
use Core\Model\Model;
use PDO;

class SerieLang extends Model
{

    private const int TTL_SECONDS = 86400; // 24h

    /**
     * Refreshes just the requested language, not all of this app's
     * languages at once, since a given request only needs its own. Returns
     * ['name' => null, 'overview' => null] both for "no translation" and
     * for an unsupported $culture - the caller doesn't need to distinguish.
     */
    public function syncForLanguage(int $idSerie, int $tvdbSerieId, string $culture, Client $client): array
    {
        $idAppacmanLang = Languages::idForCulture($culture);
        if ($idAppacmanLang === null) {
            return array('name' => null, 'overview' => null);
        }

        $row = $this->find($idSerie, $idAppacmanLang);
        if ($row === null || $this->isStale($row['synced_at'])) {
            $translation = $client->getSeriesTranslation($tvdbSerieId, Languages::tvdbCode($idAppacmanLang));
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

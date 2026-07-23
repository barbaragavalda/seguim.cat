<?php

namespace Api\Model;

use Api\Model\TheTvdb\Client;
use Core\Model\Model;
use PDO;

class SerieLang extends Model
{

    private const int TTL_SECONDS = 86400; // 24h

    /**
     * this app's own 2-letter language codes -> TheTVDB's own 3-letter ones -
     * kept here (not in Client) so a future app language or a TheTVDB code
     * change only touches this one map
     */
    private const array APP_TO_TVDB_LANGUAGE = array(
        'ca' => 'cat',
        'es' => 'spa',
        'en' => 'eng',
    );

    /**
     * returns every stored translation row for $idSerie (one per app
     * language, always all three - see upsert()), fetching/refreshing
     * whichever ones are missing or stale first. A row with NULL name/
     * overview means TheTVDB confirmed it has no translation in that
     * language - that's a stored fact, not a missing row, so it doesn't get
     * re-requested again until the TTL expires
     */
    public function syncForSerie(int $idSerie, int $tvdbSerieId, Client $client): array
    {
        $existing = $this->allForSerie($idSerie);

        foreach (self::APP_TO_TVDB_LANGUAGE as $appLanguage => $tvdbLanguage) {
            $row = $existing[$appLanguage] ?? null;
            if ($row !== null && !$this->isStale($row['synced_at'])) {
                continue;
            }

            $translation = $client->getSeriesTranslation($tvdbSerieId, $tvdbLanguage);
            $this->upsert($idSerie, $appLanguage, $translation);
        }

        return $this->allForSerie($idSerie);
    }

    /**
     * @return array<string, array> existing rows keyed by language
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
            $keyed[$row['language']] = $row;
        }
        return $keyed;
    }

    private function isStale(string $syncedAt): bool
    {
        return strtotime($syncedAt) <= (time() - self::TTL_SECONDS);
    }

    private function upsert(int $idSerie, string $language, ?array $translation): void
    {
        $sql    = '
            INSERT INTO serie_lang (id_serie, language, name, overview, synced_at)
            VALUES (:id_serie, :language, :name, :overview, NOW())
            ON DUPLICATE KEY UPDATE
                name = :name_upd, overview = :overview_upd, synced_at = NOW()
        ';
        $name     = $translation['name'] ?? null;
        $overview = $translation['overview'] ?? null;

        $params = array(
            'id_serie'     => array('value' => $idSerie, 'type' => PDO::PARAM_INT),
            'language'     => array('value' => $language, 'type' => PDO::PARAM_STR),
            'name'         => array('value' => $name, 'type' => PDO::PARAM_STR),
            'name_upd'     => array('value' => $name, 'type' => PDO::PARAM_STR),
            'overview'     => array('value' => $overview, 'type' => PDO::PARAM_STR),
            'overview_upd' => array('value' => $overview, 'type' => PDO::PARAM_STR),
        );
        $this->mysql->query($sql, $params);
    }

}

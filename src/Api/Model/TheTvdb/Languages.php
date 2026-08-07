<?php

namespace Api\Model\TheTvdb;

/**
 * id_appacman_lang (config/projects.php's language ids: 1=ca, 2=es, 3=en)
 * <-> TheTVDB's own 3-letter code. Shared by SerieLang/EpisodeLang so this
 * map only lives in one place.
 */
final class Languages
{

    private const array ALL = array(
        1 => array('culture' => 'ca', 'tvdb' => 'cat', 'country' => 'esp'),
        2 => array('culture' => 'es', 'tvdb' => 'spa', 'country' => 'esp'),
        3 => array('culture' => 'en', 'tvdb' => 'eng', 'country' => 'usa'),
    );

    public static function idForCulture(string $culture): ?int
    {
        foreach (self::ALL as $id => $language) {
            if ($language['culture'] === $culture) {
                return $id;
            }
        }
        return null;
    }

    public static function cultureForId(int $idAppacmanLang): ?string
    {
        return self::ALL[$idAppacmanLang]['culture'] ?? null;
    }

    public static function tvdbCode(int $idAppacmanLang): ?string
    {
        return self::ALL[$idAppacmanLang]['tvdb'] ?? null;
    }

    public static function tvdbCodeForCulture(string $culture): ?string
    {
        $id = self::idForCulture($culture);
        return $id !== null ? self::tvdbCode($id) : null;
    }

    /**
     * Used by MovieContentRating::bestForCountry() - ratings are tied to a
     * country, but the app only has one culture per country (ca/es ->
     * Spain, en -> USA), so this stays a plain lookup, not a preference
     * list.
     */
    public static function tvdbCountryForCulture(string $culture): ?string
    {
        foreach (self::ALL as $language) {
            if ($language['culture'] === $culture) {
                return $language['country'];
            }
        }
        return null;
    }

}

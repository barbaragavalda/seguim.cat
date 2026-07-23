<?php

namespace Api\Model\TheTvdb;

/**
 * id_appacman_lang (this project's own language lookup table -
 * config/projects.php's api sub-project languages, 1=ca, 2=es, 3=en) <->
 * TheTVDB's own 3-letter language code. Shared by Api\Model\SerieLang and
 * Api\Model\EpisodeLang so this map only lives in one place.
 */
final class Languages
{

    private const array ALL = array(
        1 => array('culture' => 'ca', 'tvdb' => 'cat'),
        2 => array('culture' => 'es', 'tvdb' => 'spa'),
        3 => array('culture' => 'en', 'tvdb' => 'eng'),
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

    public static function tvdbCode(int $idAppacmanLang): ?string
    {
        return self::ALL[$idAppacmanLang]['tvdb'] ?? null;
    }

}

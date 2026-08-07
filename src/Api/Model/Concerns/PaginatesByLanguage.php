<?php

namespace Api\Model\Concerns;

use PDO;

/**
 * Shared by Watchlist and MovieWatchlist - both page their language-scoped
 * (`id_appacman_lang`) listing query the same way, one row over PAGE_SIZE
 * to detect hasMore. Requires the using class to declare its own
 * `private const int PAGE_SIZE`.
 */
trait PaginatesByLanguage
{

    private function pageParams(int $idUser, int $idAppacmanLang, int $page): array
    {
        return array(
            'id_user'          => array('value' => $idUser, 'type' => PDO::PARAM_INT),
            'id_appacman_lang' => array('value' => $idAppacmanLang, 'type' => PDO::PARAM_INT),
            'limit'            => array('value' => self::PAGE_SIZE + 1, 'type' => PDO::PARAM_INT),
            'offset'           => array('value' => max(0, $page) * self::PAGE_SIZE, 'type' => PDO::PARAM_INT),
        );
    }

}

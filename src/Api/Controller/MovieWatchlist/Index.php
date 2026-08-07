<?php

namespace Api\Controller\MovieWatchlist;

use Api\Controller\Controller;
use Api\Model\MovieWatchlist;
use Api\Model\TheTvdb\Languages;
use Core\Routing\Attribute\Route;

/** Movie counterpart of Watchlist\Index - see its own docblock */
#[Route('/movies/watchlist', methods: ['GET'], name: 'api.movie_watchlist.index')]
class Index extends Controller
{

    private const array VALID_STATUSES = array('all', 'not_watched', 'watched');

    protected function run(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $this->error = 'status must be one of: ' . implode(', ', self::VALID_STATUSES);
            return;
        }

        $idAppacmanLang = Languages::idForCulture($this->config->getLanguage()) ?? 0;
        $page           = max(0, (int) ($_GET['page'] ?? 0));
        $search         = trim((string) ($_GET['search'] ?? '')) ?: null;

        $result = (new MovieWatchlist())->listByStatus($this->user->getID(), $idAppacmanLang, $status, $page, $search);
        $this->assign('watchlist', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

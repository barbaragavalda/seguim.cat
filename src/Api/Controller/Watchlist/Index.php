<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Languages;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

/**
 * Unified, filterable "browse everything" view - a profile-style screen
 * for reviewing series by status, separate from the app's two opinionated
 * home-screen lists (Watching, NotStarted), which stay as they are.
 */
#[Route('/watchlist', methods: ['GET'], name: 'api.watchlist.index')]
class Index extends Controller
{

    private const array VALID_STATUSES = array('all', 'removed', 'archived', 'watching', 'not_started', 'finished');

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

        $result = (new Watchlist())->listByStatus($this->user->getID(), $idAppacmanLang, $status, $page, $search);
        $this->assign('watchlist', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

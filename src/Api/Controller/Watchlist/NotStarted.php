<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Languages;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist/not-started', methods: ['GET'], name: 'api.watchlist.not_started')]
class NotStarted extends Controller
{

    protected function run(): void
    {
        $idAppacmanLang = Languages::idForCulture($this->config->getLanguage()) ?? 0;
        $page           = max(0, (int) ($_GET['page'] ?? 0));

        $result = (new Watchlist())->listNotStarted($this->user->getID(), $idAppacmanLang, $page);
        $this->assign('watchlist', $result['results']);
        $this->assign('hasMore', $result['hasMore']);
    }

}

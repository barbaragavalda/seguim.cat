<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Languages;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist/watching', methods: ['GET'], name: 'api.watchlist.watching')]
class Watching extends Controller
{

    protected function run(): void
    {
        $idAppacmanLang = Languages::idForCulture($this->config->getLanguage()) ?? 0;

        $this->assign('watchlist', (new Watchlist())->listWatching($this->user->getID(), $idAppacmanLang));
    }

}

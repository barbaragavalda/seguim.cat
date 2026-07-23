<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Languages;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist', methods: ['GET'], name: 'api.watchlist.index')]
class Index extends Controller
{

    protected function run(): void
    {
        // falls back to 0 (matches nothing) for an unsupported/unresolved
        // culture rather than guessing a default - listForUser()'s LEFT
        // JOIN just returns null name/overview in that case
        $idAppacmanLang = Languages::idForCulture($this->config->getLanguage()) ?? 0;
        $this->assign('watchlist', (new Watchlist())->listForUser($this->user->getID(), $idAppacmanLang));
    }

}

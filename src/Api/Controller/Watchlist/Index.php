<?php

namespace Api\Controller\Watchlist;

use Api\Controller\Controller;
use Api\Model\Watchlist;
use Core\Routing\Attribute\Route;

#[Route('/watchlist', methods: ['GET'], name: 'api.watchlist.index')]
class Index extends Controller
{

    protected function run(): void
    {
        $this->assign('watchlist', (new Watchlist())->listForUser($this->user->getID()));
    }

}

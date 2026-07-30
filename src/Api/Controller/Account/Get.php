<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Api\Model\AccountStats;
use Api\Model\TheTvdb\Languages;
use Core\Routing\Attribute\Route;

#[Route('/account', methods: ['GET'], name: 'api.account.get')]
class Get extends Controller
{

    protected function run(): void
    {
        $info = $this->user->getInfo();
        $this->assign('username', $info['username']);
        $this->assign('email', $info['email']);
        $this->assign('language', Languages::cultureForId((int) ($info['id_appacman_lang'] ?? 0)));
        $this->assign('stats', (new AccountStats())->forUser($this->user->getID()));
    }

}

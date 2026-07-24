<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Routing\Attribute\Route;

#[Route('/account', methods: ['GET'], name: 'api.account.get')]
class Get extends Controller
{

    protected function run(): void
    {
        $info = $this->user->getInfo();
        $this->assign('username', $info['username']);
        $this->assign('email', $info['email']);
    }

}

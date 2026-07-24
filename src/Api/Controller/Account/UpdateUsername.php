<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Routing\Attribute\Route;
use Webservice\Model\User;

#[Route('/account/username', methods: ['POST'], name: 'api.account.update_username')]
class UpdateUsername extends Controller
{

    protected function run(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));

        if (!preg_match(User::USERNAME_PATTERN, $username)) {
            $this->error = $this->translate(
                'Username must be 3-20 characters long and contain only letters, numbers, "_" or "."'
            );
            return;
        }

        if (!$this->user->updateUsername($username)) {
            $this->error = $this->translate('That username is already taken.');
            return;
        }

        $this->assign('username', $username);
    }

}

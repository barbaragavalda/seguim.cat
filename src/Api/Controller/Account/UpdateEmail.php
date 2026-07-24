<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Routing\Attribute\Route;

#[Route('/account/email', methods: ['POST'], name: 'api.account.update_email')]
class UpdateEmail extends Controller
{

    protected function run(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error = $this->translate('Enter a valid email address.');
            return;
        }

        if (!$this->user->updateEmail($email)) {
            $this->error = $this->translate('That email is already registered.');
            return;
        }

        $this->assign('email', $email);
    }

}

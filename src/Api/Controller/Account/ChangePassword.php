<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Routing\Attribute\Route;

#[Route('/account/password', methods: ['POST'], name: 'api.account.change_password')]
class ChangePassword extends Controller
{

    protected function run(): void
    {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');

        if (!$currentPassword || !$newPassword) {
            $this->error = $this->translate('All fields are required.');
            return;
        }

        // unlike Webservice\Controller\ResetPassword (a "forgot password"
        // flow, where the requester's identity is only as certain as the
        // emailed code), this request already carries a valid session token
        // - no extra confirmation needed, and no reason to revoke other
        // devices the way a reset does
        if (!$this->user->verifyPassword($currentPassword)) {
            $this->error = $this->translate('Current password is incorrect.');
            return;
        }

        $this->user->updatePassword($newPassword);
    }

}

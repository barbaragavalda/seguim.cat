<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Routing\Attribute\Route;
use Webservice\Model\User;
use Webservice\Model\UserToken;

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
        if (strlen($newPassword) < User::PASSWORD_MIN_LENGTH) {
            $this->error = sprintf(
                $this->translate('Password must be at least %d characters long.'),
                User::PASSWORD_MIN_LENGTH
            );
            return;
        }

        // a valid session token alone isn't proof of knowing the password (stolen/shared device)
        if (!$this->user->verifyPassword($currentPassword)) {
            $this->error = $this->translate('Current password is incorrect.');
            return;
        }

        $this->user->updatePassword($newPassword);

        // revoke all devices in case the old password was compromised, then re-issue for this session
        (new UserToken())->revokeAllForUser($this->user->getID());
        $this->assign('token', (new UserToken())->issue($this->user->getID()));
    }

}

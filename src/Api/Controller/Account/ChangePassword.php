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

        // this request already carries a valid session token, but that
        // alone isn't proof the caller knows the account's actual password
        // (a stolen/shared-device token would still pass) - requiring it
        // here too is what makes this safe to expose with no extra
        // out-of-band confirmation step, unlike Webservice\Controller\
        // ResetPassword's emailed-code flow
        if (!$this->user->verifyPassword($currentPassword)) {
            $this->error = $this->translate('Current password is incorrect.');
            return;
        }

        $this->user->updatePassword($newPassword);

        // a changed password can mean the old one was known to someone it
        // shouldn't have been - revoke every device's token, same reasoning
        // as ResetPassword, then immediately re-issue one for *this* session
        // so the device making the change isn't logged out for a change it
        // just asked for itself
        (new UserToken())->revokeAllForUser($this->user->getID());
        $this->assign('token', (new UserToken())->issue($this->user->getID()));
    }

}

<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Routing\Attribute\Route;
use Webservice\Model\EmailChange;

#[Route('/account/email/confirm', methods: ['POST'], name: 'api.account.confirm_email')]
class ConfirmEmailChange extends Controller
{

    protected function run(): void
    {
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!$code) {
            $this->error = $this->translate('Code is required.');
            return;
        }

        $newEmail = (new EmailChange())->redeem($this->user->getID(), $code);
        if ($newEmail === null) {
            $this->error = $this->translate('Invalid or expired code.');
            return;
        }

        // re-checked here too - another account could take this address during
        // the 15-minute window between request and confirm
        if (!$this->user->updateEmail($newEmail)) {
            $this->error = $this->translate('That email is already registered.');
            return;
        }

        $this->assign('email', $newEmail);
    }

}

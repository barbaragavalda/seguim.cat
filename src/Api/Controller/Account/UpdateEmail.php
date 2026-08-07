<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Core\Controller\CacheManager;
use Core\Model\Utils\Mail;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;
use Core\Utils\Language;
use Throwable;
use Webservice\Model\EmailChange;
use Webservice\Model\User;

/**
 * Only *requests* the change - it takes effect once confirmed via a code
 * sent to the new address (see ConfirmEmailChange), so a stolen/shared
 * session can't hijack the account. Also notifies the *current* email in
 * case the request itself wasn't legitimate.
 */
#[Route('/account/email', methods: ['POST'], name: 'api.account.update_email')]
class UpdateEmail extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Language $language)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error = $this->translate('Enter a valid email address.');
            return;
        }

        $existing = new User();
        if ($existing->loadWithEmail($email) && $existing->getID() !== $this->user->getID()) {
            $this->error = $this->translate('That email is already registered.');
            return;
        }

        $code = (new EmailChange())->create($this->user->getID(), $email);

        // sent in the account's own stored language (kept current by
        // Account\UpdateLanguage), not necessarily this request's Accept-Language
        $culture = $this->language->getCulture($this->user->getInfo()['id_appacman_lang'] ?? null);
        $this->language->withCulture($culture, function () use ($email, $code) {
            $this->sendConfirmationCode($email, $code);
            $this->sendChangeNotice($this->user->getInfo()['email'], $email);
        });
    }

    private function sendConfirmationCode(string $newEmail, string $code): void
    {
        $subject = $this->translate('Confirm your new email address');
        $body    = $this->translate('Your confirmation code is:') . ' <strong>' . $code . '</strong><br>'
            . $this->translate('It expires in 15 minutes.');

        $this->send($newEmail, $subject, $body, 'confirmation code');

        if (IS_DEV) {
            error_log('UpdateEmail[dev]: confirmation code for user ' . $this->user->getID() . ' is ' . $code);
        }
    }

    private function sendChangeNotice(string $oldEmail, string $newEmail): void
    {
        $subject = $this->translate("Your account's email is being changed");
        $body    = $this->translate("A request was made to change this account's email to:")
            . ' <strong>' . $newEmail . '</strong><br>'
            . $this->translate("If this wasn't you, change your password immediately.");

        $this->send($oldEmail, $subject, $body, 'change notice');
    }

    private function send(string $to, string $subject, string $body, string $label): void
    {
        try {
            (new Mail())->send(array(), array(array('email' => $to, 'name' => '')), $subject, $body);
        } catch (Throwable $e) {
            // never surface a mail/config problem to the client - see ForgotPassword
            error_log('UpdateEmail: failed to send ' . $label . ' - ' . $e->getMessage());
        }
    }

}

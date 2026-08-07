<?php

namespace Api\Controller\Login;

use Api\Controller\Controller;
use Api\Model\GoogleAuth\TokenVerifier;
use Api\Model\UserGoogle;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;
use Core\Utils\Language;
use Webservice\Model\User;
use Webservice\Model\UserToken;

/**
 * "Sign in with Google" - tv-tracker-local-only, doesn't touch the shared
 * Webservice\Controller\{Login,Register}. Resolves to an existing
 * user_google link, a matching-email account, or a new registration.
 */
#[Route('/login/google', methods: ['POST'], name: 'api.login.google')]
class Google extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Language $language)
    {
        parent::__construct($config, $modelCache);
    }

    protected function requiresUserToken(): bool
    {
        return false;
    }

    protected function run(): void
    {
        $idToken = trim((string) ($_POST['id_token'] ?? ''));
        if (!$idToken) {
            $this->error = $this->translate('Invalid email or password.');
            return;
        }

        $clientIds = array_values((array) $this->config->get('google')['client_ids']);
        $verified  = (new TokenVerifier())->verify($idToken, $clientIds);
        if ($verified === null) {
            $this->error = $this->translate('Invalid email or password.');
            return;
        }

        $userGoogle = new UserGoogle();
        $idUser     = $userGoogle->findUserId($verified['sub']);

        if ($idUser === null) {
            $user = new User();
            if ($user->loadWithEmail($verified['email'])) {
                // existing password-based account, first Google sign-in - link instead of duplicating
                $idUser = (int) $user->getInfo()['id_user'];
            } else {
                $idAppacmanLang = $this->language->getLanguageID($this->config->getLanguage());
                // random password: `password` column is NOT NULL but Google
                // is the only way into a Google-created account
                $idUser = $user->register(
                    $verified['email'],
                    bin2hex(random_bytes(32)),
                    $this->generateUsername($verified['email']),
                    $idAppacmanLang
                );
                if (!$idUser) {
                    $this->error = $this->translate('Registration failed.');
                    return;
                }
            }
            $userGoogle->link($idUser, $verified['sub']);
        }

        $token = (new UserToken())->issue($idUser);
        $this->assign('token', $token);
    }

    /**
     * Derives a username from the email's local part - Google sign-up has
     * no username field of its own to ask for one up front.
     */
    private function generateUsername(string $email): string
    {
        $local = strstr($email, '@', true);
        $base  = substr((string) preg_replace('/[^a-zA-Z0-9_.]/', '', $local ?: $email), 0, 16);
        if (strlen($base) < 3) {
            $base = 'user' . $base;
        }

        $checker  = new User();
        $username = $base;
        $suffix   = 0;
        while ($checker->loadWithUsername($username)) {
            $suffix++;
            $username = substr($base, 0, 20 - strlen((string) $suffix)) . $suffix;
        }

        return $username;
    }

}

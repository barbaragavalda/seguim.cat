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
 * "Sign in with Google" - a tv-tracker-local-only addition, not part of the
 * shared Webservice\Controller\{Login,Register} (those stay untouched, this
 * is purely additive). Verifies the ID token the Flutter app already
 * obtained from Google (see TokenVerifier), then either signs in an
 * already-linked account (user_google), links a matching-email account
 * signing in with Google for the first time, or registers a brand new one -
 * same three-way resolution any "sign in with X" flow needs.
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
                // an existing password-based account signing in with Google
                // for the first time - link it rather than creating a
                // duplicate, same email either way
                $idUser = (int) $user->getInfo()['id_user'];
            } else {
                $idAppacmanLang = $this->language->getLanguageID($this->config->getLanguage());
                // the random password is never given to this user - Google
                // is the only way into a Google-created account. User's own
                // `password` column is NOT NULL, so it still needs a value
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
     * derives a username candidate from the email's local part (sanitized
     * down to User::USERNAME_PATTERN's allowed charset), appending a
     * numeric suffix until it's free - a Google sign-up has no username
     * field of its own to ask for one up front
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

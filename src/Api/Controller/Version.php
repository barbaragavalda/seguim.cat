<?php

namespace Api\Controller;

use Core\Routing\Attribute\Route;

/**
 * Reads the repo's own VERSION file (bumped automatically on push - see
 * .github/workflows/bump-version.yml) rather than hardcoding a value here,
 * so this endpoint can never drift out of sync with what's actually
 * deployed. requiresUserToken() => false only means no *user* is needed
 * (same as Register/Login) - the app's own shared secret is still required,
 * per WebserviceController::checkToken()'s own unconditional check.
 */
#[Route('/version', methods: ['GET'], name: 'api.version')]
class Version extends Controller
{

    protected function requiresUserToken(): bool
    {
        return false;
    }

    protected function run(): void
    {
        $version = trim((string) @file_get_contents(DIR_ROOT . 'VERSION'));
        $this->assign('version', $version !== '' ? $version : null);
    }

}

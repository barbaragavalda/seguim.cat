<?php

namespace Api\Controller;

use Core\Routing\Attribute\Route;

/**
 * Reads the repo's VERSION file (bumped on push by
 * .github/workflows/bump-version.yml) rather than hardcoding it here.
 * requiresUserToken() => false only skips the *user* check - the app's
 * shared secret is still required, per WebserviceController::checkToken().
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

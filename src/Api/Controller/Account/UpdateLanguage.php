<?php

namespace Api\Controller\Account;

use Api\Controller\Controller;
use Api\Model\TheTvdb\Languages;
use Core\Routing\Attribute\Route;

#[Route('/account/language', methods: ['POST'], name: 'api.account.update_language')]
class UpdateLanguage extends Controller
{

    protected function run(): void
    {
        $culture = trim((string) ($_POST['language'] ?? ''));
        $id      = Languages::idForCulture($culture);

        if ($id === null) {
            $this->error = $this->translate('Unsupported language.');
            return;
        }

        $this->user->updateLanguage($id);

        $this->assign('language', $culture);
    }

}

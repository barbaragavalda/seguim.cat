<?php

namespace Web\Controller;

use Core\Routing\Attribute\Route;
use Web\Model\Content;

/**
 * '/privacitat' is the canonical (Catalan) slug - AttributeRouteLoader::translatePath()
 * runs it through gettext to resolve per-language, same catalog as the rest of the app's
 * copy. Always link to this route via Twig's url('web.privacy'), never hardcode the segment.
 */
#[Route('/privacitat', name: 'web.privacy')]
class Privacy extends Controller
{

    public function build(): void
    {
        $this->assign('privacy', Content::privacy());
        $this->assign('footer', Content::footer());
        $this->template('privacy.twig');
    }

}

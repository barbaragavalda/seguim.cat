<?php

namespace Web\Controller;

use Core\Routing\Attribute\Route;
use Web\Model\Content;

/**
 * Deliberately the same literal path segment ('/privacitat') under every
 * language prefix (/ca/privacitat, /es/privacitat, /en/privacitat) rather
 * than a per-language translated slug - AttributeRouteLoader::translatePath()
 * would need a gettext msgid for the segment itself, and this URL doesn't
 * need to be pretty, just stable (it's what's pasted into Google Auth
 * Platform's Privacy Policy field, App Store Connect, etc).
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

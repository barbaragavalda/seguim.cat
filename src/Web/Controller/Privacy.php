<?php

namespace Web\Controller;

use Core\Routing\Attribute\Route;
use Web\Model\Content;

/**
 * The '/privacitat' literal here is the canonical/source (Catalan) slug -
 * AttributeRouteLoader::translatePath() runs it through gettext while
 * building the route, so it resolves per-language via the same
 * locale/{es_ES,en_GB}/LC_MESSAGES/messenges.po catalog as the rest of this
 * app's copy (msgid "privacitat" -> "privacidad"/"privacy"). Always
 * generate links to this route with Twig's url('web.privacy') rather than
 * hardcoding the segment, so it always resolves to the current language's
 * own translated slug.
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

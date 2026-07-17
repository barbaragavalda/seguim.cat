<?php

namespace Web\Controller;

use Core\Routing\Attribute\Route;

#[Route('/404', name: 'web.default')]
class DefaultController extends Controller
{

    public function build(): void
    {
        $this->template('404.twig', 404);
    }

}

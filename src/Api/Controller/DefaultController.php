<?php

namespace Api\Controller;

use Core\Routing\Attribute\Route;

#[Route('/404', name: 'api.default')]
class DefaultController extends Controller
{

    public function build(): void
    {
        $this->assign('error', 404);
        $this->json();
    }

    protected function run(): void
    {
    }

}

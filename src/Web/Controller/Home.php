<?php

namespace Web\Controller;

use Core\Routing\Attribute\Route;
use Web\Model\Content;
use Web\Model\Posters;

#[Route('/', name: 'web.home')]
class Home extends Controller
{

    private const int POSTER_COUNT = 4;

    public function build(): void
    {
        $this->assign('home', Content::home());
        $this->assign('footer', Content::footer());
        $this->assign('posters', (new Posters())->random(self::POSTER_COUNT));
        $this->template('home.twig');
    }

}

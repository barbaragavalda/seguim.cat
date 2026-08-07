<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\MovieImportPending;
use Core\Routing\Attribute\Route;

/** Dismisses a pending title - none of the candidates fit, and the user doesn't want to be asked again */
#[Route('/import/movies/pending/{id}', methods: ['DELETE'], name: 'api.import.movies.pending.skip', requirements: ['id' => '\d+'])]
class SkipPendingMovie extends Controller
{

    protected function run(): void
    {
        $id       = (int) $this->getParam('id');
        $skipped  = (new MovieImportPending())->skip($id, $this->user->getID());
        if (!$skipped) {
            $this->error = '404';
        }
    }

}

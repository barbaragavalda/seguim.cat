<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\SeriesImportPending;
use Core\Routing\Attribute\Route;

/** Dismisses a pending show - none of the candidates fit, and the user doesn't want to be asked again */
#[Route('/import/series/pending/{id}', methods: ['DELETE'], name: 'api.import.series.pending.skip', requirements: ['id' => '\d+'])]
class SkipPendingSeries extends Controller
{

    protected function run(): void
    {
        $id      = (int) $this->getParam('id');
        $skipped = (new SeriesImportPending())->skip($id, $this->user->getID());
        if (!$skipped) {
            $this->error = '404';
        }
    }

}

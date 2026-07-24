<?php

namespace Api\Controller\Import;

use Api\Controller\Controller;
use Api\Model\TvTimeImport;
use Core\Routing\Attribute\Route;

#[Route('/import/tvtime/{id}', methods: ['GET'], name: 'api.import.tvtime.status', requirements: ['id' => '\d+'])]
class TvTimeStatus extends Controller
{

    protected function run(): void
    {
        $id  = (int) $this->getParam('id');
        $job = (new TvTimeImport())->findForUser($id, $this->user->getID());
        if ($job === null) {
            $this->error = '404';
            return;
        }

        $this->assign('status', $job['status']);
        $this->assign('summary', $job['summary'] !== null ? json_decode($job['summary'], true) : null);
        $this->assign('error_message', $job['error']);
    }

}

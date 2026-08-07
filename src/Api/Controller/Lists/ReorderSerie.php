<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Core\Routing\Attribute\Route;

/** `after` omitted/empty moves to the front - same relative-to-neighbor reasoning as Lists\Reorder */
#[Route('/lists/{id}/series/{tvdbId}/reorder', methods: ['POST'], name: 'api.lists.reorder_serie', requirements: ['id' => '\d+', 'tvdbId' => '\d+'])]
class ReorderSerie extends Controller
{

    protected function run(): void
    {
        $id     = (int) $this->getParam('id');
        $tvdbId = (int) $this->getParam('tvdbId');
        $after  = ($_POST['after'] ?? '') !== '' ? (int) $_POST['after'] : null;

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        $series = new Series();
        if (!$series->loadWithTvdbId($tvdbId)) {
            $this->error = '404';
            return;
        }
        $idSerie = $series->getInfo()['id_serie'];

        $afterIdSerie = null;
        if ($after !== null) {
            $afterSeries = new Series();
            if (!$afterSeries->loadWithTvdbId($after)) {
                $this->error = 'Invalid `after` reference.';
                return;
            }
            $afterIdSerie = $afterSeries->getInfo()['id_serie'];
        }

        (new UserListSerie())->moveAfter($id, $idSerie, $afterIdSerie);
    }

}

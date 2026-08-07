<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Series;
use Api\Model\TheTvdb\Client;
use Api\Model\UserList;
use Api\Model\UserListSerie;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/lists/{id}/series/{tvdbId}', methods: ['POST'], name: 'api.lists.add_serie', requirements: ['id' => '\d+', 'tvdbId' => '\d+'])]
class AddSerie extends Controller
{

    public function __construct(Config $config, CacheManager $modelCache, private readonly Client $client)
    {
        parent::__construct($config, $modelCache);
    }

    protected function run(): void
    {
        $id     = (int) $this->getParam('id');
        $tvdbId = (int) $this->getParam('tvdbId');

        if (!(new UserList())->belongsToUser($this->user->getID(), $id)) {
            $this->error = '404';
            return;
        }

        // sync() first so user_list_serie always points at a real row, even on first touch - same reasoning as Watchlist/Add
        $info = (new Series())->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        (new UserListSerie())->add($id, $info['id_serie']);
    }

}

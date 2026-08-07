<?php

namespace Api\Controller\Lists;

use Api\Controller\Controller;
use Api\Model\Movie;
use Api\Model\TheTvdb\Client;
use Api\Model\UserList;
use Api\Model\UserListMovie;
use Core\Controller\CacheManager;
use Core\Routing\Attribute\Route;
use Core\Utils\Config;

#[Route('/lists/{id}/movies/{tvdbId}', methods: ['POST'], name: 'api.lists.add_movie', requirements: ['id' => '\d+', 'tvdbId' => '\d+'])]
class AddMovie extends Controller
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

        // sync() first so user_list_movie always points at a real local movie row, same reasoning as Lists\AddSerie
        $info = (new Movie())->sync($tvdbId, $this->client);
        if (empty($info)) {
            $this->error = '404';
            return;
        }

        (new UserListMovie())->add($id, $info['id_movie']);
    }

}

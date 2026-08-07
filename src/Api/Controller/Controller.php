<?php

namespace Api\Controller;

use Webservice\Controller\WebserviceController;

abstract class Controller extends WebserviceController
{

    /**
     * Core\Controller\Controller::loadCache() requires a Model - this is
     * the same get-or-compute-and-save wrapping for any other callable
     * (e.g. an external HTTP client call).
     */
    protected function cached(string $key, int $ttl, callable $compute): mixed
    {
        $result = $this->modelCache->getCache(array('key' => $key, 'ttl' => $ttl));
        if ($result === null) {
            $result = $compute();
            $this->modelCache->saveCache($result);
        }
        return $result;
    }

}

<?php

namespace Api\Model\TheTvdb;

use Core\Utils\Config;
use RuntimeException;

class Client
{

    private const string BASE_URL = 'https://api4.thetvdb.com/v4';

    private Config $config;

    private string $tokenCacheDir;

    private string $tokenCacheFile;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();

        $environment          = IS_DEV ? 'dev' : 'prod';
        $this->tokenCacheDir  = DIR_ROOT . 'src/cache/' . $environment . '/thetvdb/';
        $this->tokenCacheFile = $this->tokenCacheDir . 'token.json';
    }

    public function search(string $query): array
    {
        $response = $this->request('GET', '/search', array('query' => $query, 'type' => 'series'));
        return $response['data'] ?? array();
    }

    public function getSeries(int $tvdbId): array
    {
        $response = $this->request('GET', '/series/' . $tvdbId);
        return $response['data'] ?? array();
    }

    /**
     * only fetches page 0 - a single regular series' episode list fits
     * comfortably in one TVDB page; full pagination is out of scope
     */
    public function getSeriesEpisodes(int $tvdbSeriesId, string $seasonType = 'official'): array
    {
        $response = $this->request(
            'GET',
            '/series/' . $tvdbSeriesId . '/episodes/' . $seasonType,
            array('page' => 0)
        );
        return $response['data']['episodes'] ?? array();
    }

    private function request(string $method, string $path, array $query = array()): array
    {
        $url = self::BASE_URL . $path;
        if (count($query)) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->call($method, $url, $this->getToken());

        // the envelope's own "status" is the only reliable signal of a
        // 401/expired-token condition (see call() for why the raw HTTP
        // status code isn't used instead); retry exactly once, forcing a
        // fresh login
        if (($response['status'] ?? null) !== 'success') {
            $response = $this->call($method, $url, $this->login());
        }

        return is_array($response) ? $response : array();
    }

    private function call(string $method, string $url, string $token): array
    {
        $headers = array('Authorization: Bearer ' . $token, 'Content-Type: application/json');
        return $this->httpRequest($method, $url, $headers);
    }

    /**
     * Deliberately not Core\Model\Utils\Curl - its make() unconditionally
     * routes every request through a CURLOPT_PROXY of
     * $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'] whenever
     * IS_DEV is true (i.e. this app's own web server address), which has
     * nothing to do with reaching a genuine third-party host like TheTVDB -
     * confirmed empirically (curl error 7, "couldn't connect", reproduced
     * by manually setting --proxy to that same address) rather than
     * assumed. Also skips Curl::get()'s unrelated GET+params bug (it puts
     * $params into CURLOPT_POSTFIELDS even for a GET request, which makes
     * libcurl silently send a POST instead) by never passing query params
     * as a separate array - request() already bakes them into $url.
     */
    private function httpRequest(string $method, string $url, array $headers, ?array $jsonBody = null): array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            if ($jsonBody !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            }
        }

        $output = curl_exec($curl);

        $decoded = $output !== false ? json_decode($output, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

    private function getToken(): string
    {
        return $this->readCachedToken() ?? $this->login();
    }

    private function login(): string
    {
        // Config::get() returns '' (not an array) when the key is entirely
        // absent - i.e. config/api/{dev,prod}/thetvdb.php hasn't been copied
        // from its .dist yet - so guard explicitly instead of a confusing
        // "cannot access offset of type string on string" TypeError below
        $tvdbConfig = $this->config->get('thetvdb');
        if (!is_array($tvdbConfig) || empty($tvdbConfig['apikey'])) {
            throw new RuntimeException(
                'TheTVDB API key not configured - copy config/api/dev/thetvdb.php.dist to '
                . 'thetvdb.php and fill in a real apikey'
            );
        }

        $body = array('apikey' => $tvdbConfig['apikey']);
        if (!empty($tvdbConfig['pin'])) {
            $body['pin'] = $tvdbConfig['pin'];
        }

        $response = $this->httpRequest(
            'POST',
            self::BASE_URL . '/login',
            array('Content-Type: application/json'),
            $body
        );
        $token    = $response['data']['token'] ?? null;
        if (!$token) {
            throw new RuntimeException('TheTVDB login failed');
        }

        $this->writeCachedToken($token);
        return $token;
    }

    private function readCachedToken(): ?string
    {
        if (!file_exists($this->tokenCacheFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->tokenCacheFile), true);
        if (!is_array($data) || empty($data['token']) || ($data['expires_at'] ?? 0) <= time()) {
            return null;
        }
        return $data['token'];
    }

    private function writeCachedToken(string $token): void
    {
        if (!is_dir($this->tokenCacheDir)) {
            @mkdir($this->tokenCacheDir, 0777, true);
        }
        // TVDB bearer tokens are valid ~1 month; cache 25 days to stay safe
        file_put_contents(
            $this->tokenCacheFile,
            json_encode(array('token' => $token, 'expires_at' => time() + 25 * 86400))
        );
    }

}

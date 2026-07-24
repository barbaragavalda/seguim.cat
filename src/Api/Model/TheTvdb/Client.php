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

    /**
     * $tvdbLanguageCode picks each result's name/overview out of its own
     * inline `translations`/`overviews` maps (TheTVDB's search index already
     * returns every language it has for a result, confirmed empirically -
     * no separate per-result translation call needed here, unlike series/
     * episode detail) - falls back to the result's own primary-language
     * name/overview if that specific language isn't in the map
     */
    public function search(string $query, int $page, string $tvdbLanguageCode): array
    {
        $response = $this->request(
            'GET',
            '/search',
            array('query' => $query, 'type' => 'series', 'page' => $page)
        );
        $results = $response['data'] ?? array();

        foreach ($results as &$result) {
            $result['name']     = $result['translations'][$tvdbLanguageCode] ?? $result['name'] ?? null;
            $result['overview'] = $result['overviews'][$tvdbLanguageCode] ?? $result['overview'] ?? null;
            // renamed to match serie.image - no background/fanart field
            // exists on a search result at all (confirmed empirically), and
            // fetching one would mean a separate /artworks call per result;
            // it only ever appears once a series is actually opened
            // (Series/Detail, serie.background)
            $result['image'] = $result['image_url'] ?? null;
            unset($result['image_url']);
        }
        unset($result);

        return array(
            'results' => $results,
            'hasMore' => ($response['links']['next'] ?? null) !== null,
        );
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

    /**
     * unlike getSeriesTranslation() (one series-level record), this returns
     * *every* episode of the series translated into $tvdbLanguageCode in a
     * single call - confirmed empirically (149 episodes back for a 149-
     * episode series, same as the untranslated getSeriesEpisodes() call),
     * so no need to fetch translations per-episode
     */
    public function getSeriesEpisodesTranslated(int $tvdbSeriesId, string $tvdbLanguageCode, string $seasonType = 'official'): array
    {
        $response = $this->request(
            'GET',
            '/series/' . $tvdbSeriesId . '/episodes/' . $seasonType . '/' . $tvdbLanguageCode,
            array('page' => 0)
        );
        return $response['data']['episodes'] ?? array();
    }

    /**
     * $tvdbLanguageCode is TheTVDB's own 3-letter code (e.g. "cat"/"spa"/
     * "eng"), not this app's 2-letter one - see Api\Model\TheTvdb\Languages
     * for that mapping. Returns null (not an error/exception) when TheTVDB
     * has no translation in that language - a normal, expected 404 for most
     * shows in most languages, not a failure worth retrying or logging
     */
    public function getSeriesTranslation(int $tvdbSeriesId, string $tvdbLanguageCode): ?array
    {
        $response = $this->request('GET', '/series/' . $tvdbSeriesId . '/translations/' . $tvdbLanguageCode);
        return !empty($response['data']) ? $response['data'] : null;
    }

    /**
     * a series typically has dozens of background/fanart images (artwork
     * type 3, confirmed via GET /artwork/types) - only the single
     * highest-scored one is kept, not the full list. TheTVDB already
     * returns them sorted by score descending, but that's re-checked here
     * rather than assumed
     */
    public function getSeriesBackground(int $tvdbSeriesId): ?string
    {
        $response = $this->request('GET', '/series/' . $tvdbSeriesId . '/artworks', array('type' => 3));
        $artworks = $response['data']['artworks'] ?? array();
        if (empty($artworks)) {
            return null;
        }

        usort($artworks, fn(array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return $artworks[0]['image'] ?? null;
    }

    private function request(string $method, string $path, array $query = array()): array
    {
        $url = self::BASE_URL . $path;
        if (count($query)) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->call($method, $url, $this->getToken());

        // retry exactly once, forcing a fresh login - but ONLY on a real
        // 401, not on every non-"success" envelope: a legitimate 404 (e.g.
        // getSeriesTranslation() asking for a language TheTVDB doesn't have)
        // is a normal outcome, not an auth failure, and retrying it would
        // just waste a forced re-login for the same expected 404 again
        if ($response['httpStatus'] === 401) {
            $response = $this->call($method, $url, $this->login());
        }

        return $response['body'];
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
     *
     * @return array{httpStatus: int, body: array}
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

        $output     = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $decoded = $output !== false ? json_decode($output, true) : null;
        return array('httpStatus' => $httpStatus, 'body' => is_array($decoded) ? $decoded : array());
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
        $token    = $response['body']['data']['token'] ?? null;
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

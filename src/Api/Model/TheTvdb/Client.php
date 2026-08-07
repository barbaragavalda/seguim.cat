<?php

namespace Api\Model\TheTvdb;

use Core\Utils\Config;
use RuntimeException;

class Client
{

    private const string BASE_URL = 'https://api4.thetvdb.com/v4';

    /**
     * artwork type 15 = "Background" for a movie (GET /artwork/types) - NOT
     * type 3, which Series uses; same English name, unrelated ids
     */
    private const int MOVIE_BACKGROUND_ARTWORK_TYPE = 15;

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

    public function search(string $query, int $page, string $tvdbLanguageCode): array
    {
        return $this->performSearch($query, $page, 'series', $tvdbLanguageCode);
    }

    public function searchMovies(string $query, int $page, string $tvdbLanguageCode): array
    {
        return $this->performSearch($query, $page, 'movie', $tvdbLanguageCode);
    }

    /**
     * Omitting $type (see performSearch()) returns every TheTVDB record
     * type ranked together, each still carrying its own `type` field to
     * tell them apart. Used by the app's unified search; search()/
     * searchMovies() stay type-scoped for flows that only make sense for
     * one kind.
     */
    public function searchAll(string $query, int $page, string $tvdbLanguageCode): array
    {
        return $this->performSearch($query, $page, null, $tvdbLanguageCode);
    }

    /**
     * $tvdbLanguageCode picks name/overview from the result's own inline
     * `translations`/`overviews` maps (search results carry every language
     * already, unlike series/episode/movie detail) - falls back to the
     * primary-language name/overview if missing. $type is TheTVDB's search
     * type ('series'/'movie'), or null for every type (searchAll()).
     */
    private function performSearch(string $query, int $page, ?string $type, string $tvdbLanguageCode): array
    {
        $query2 = array('query' => $query, 'page' => $page);
        if ($type !== null) {
            $query2['type'] = $type;
        }
        $response = $this->request('GET', '/search', $query2);
        $results  = $response['data'] ?? array();

        if ($type === null) {
            // searchAll() returns every TheTVDB record type, not just
            // series/movie (e.g. "Encanto" also matches "Encanto
            // Enterprises", a company) - drop the rest, the UI only knows
            // how to display these two kinds
            $results = array_values(array_filter(
                $results,
                fn(array $result): bool => in_array($result['type'] ?? null, array('series', 'movie'), true)
            ));
        }

        foreach ($results as &$result) {
            $result['name']     = $result['translations'][$tvdbLanguageCode] ?? $result['name'] ?? null;
            $result['overview'] = $result['overviews'][$tvdbLanguageCode] ?? $result['overview'] ?? null;
            // renamed to match serie.image/movie.image - a search result
            // has no background/fanart field at all; that only appears
            // once opened (Detail, .background)
            $result['image'] = $result['image_url'] ?? null;
            unset($result['image_url']);
        }
        unset($result);

        return array(
            'results' => $results,
            'hasMore' => ($response['links']['next'] ?? null) !== null,
        );
    }

    /**
     * /extended, not the base /series/{id} - needed for `genres`, absent
     * from the base record. Unlike getMovie(), no extra background/overview
     * request is needed - /extended already includes both directly.
     */
    public function getSeries(int $tvdbId): array
    {
        $response = $this->request('GET', '/series/' . $tvdbId . '/extended');
        return $response['data'] ?? array();
    }

    /**
     * /extended already returns the full artworks list inline, so no
     * separate background request is needed (unlike getSeries()/
     * getSeriesBackground()). It does NOT include a top-level 'overview'
     * though (only 'overviewTranslations') - a second request fills it in
     * from the movie's original-language translation.
     */
    public function getMovie(int $tvdbId): array
    {
        $response = $this->request('GET', '/movies/' . $tvdbId . '/extended');
        $data     = $response['data'] ?? array();
        if (empty($data)) {
            return array();
        }

        $data['background'] = $this->getMovieBackground($data['artworks'] ?? array());
        if (!empty($data['originalLanguage'])) {
            $translation       = $this->getMovieTranslation($tvdbId, $data['originalLanguage']);
            $data['overview']  = $translation['overview'] ?? null;
        }
        return $data;
    }

    private function getMovieBackground(array $artworks): ?string
    {
        $backgrounds = array_values(array_filter(
            $artworks,
            fn(array $artwork): bool => ($artwork['type'] ?? null) === self::MOVIE_BACKGROUND_ARTWORK_TYPE
        ));
        if (empty($backgrounds)) {
            return null;
        }

        usort($backgrounds, fn(array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return $backgrounds[0]['image'] ?? null;
    }

    /**
     * counterpart of getSeriesTranslation() - same shape, same
     * null-on-no-translation behavior (a missing translation is a 404, not
     * an error)
     */
    public function getMovieTranslation(int $tvdbId, string $tvdbLanguageCode): ?array
    {
        $response = $this->request('GET', '/movies/' . $tvdbId . '/translations/' . $tvdbLanguageCode);
        return !empty($response['data']) ? $response['data'] : null;
    }

    /**
     * Follows every page - a single TVDB page tops out at 500 episodes,
     * which a long-running daily/weekly show can exceed (silently
     * truncating higher seasons if only page 0 is fetched).
     */
    public function getSeriesEpisodes(int $tvdbSeriesId, string $seasonType = 'official'): array
    {
        return $this->getAllEpisodePages('/series/' . $tvdbSeriesId . '/episodes/' . $seasonType);
    }

    /**
     * Unlike getSeriesTranslation() (one series-level record), returns
     * every episode translated into $tvdbLanguageCode in one call - no need
     * to fetch translations per-episode. Paginated the same way as
     * getSeriesEpisodes().
     */
    public function getSeriesEpisodesTranslated(int $tvdbSeriesId, string $tvdbLanguageCode, string $seasonType = 'official'): array
    {
        return $this->getAllEpisodePages('/series/' . $tvdbSeriesId . '/episodes/' . $seasonType . '/' . $tvdbLanguageCode);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAllEpisodePages(string $path): array
    {
        $episodes = array();
        $page     = 0;
        do {
            $response = $this->request('GET', $path, array('page' => $page));
            $episodes = array_merge($episodes, $response['data']['episodes'] ?? array());
            $hasNext  = ($response['links']['next'] ?? null) !== null;
            $page++;
        } while ($hasNext);

        return $episodes;
    }

    /**
     * $tvdbLanguageCode is TheTVDB's 3-letter code, not this app's 2-letter
     * one - see Languages for that mapping. Returns null (not an exception)
     * on a missing translation - a normal, expected 404.
     */
    public function getSeriesTranslation(int $tvdbSeriesId, string $tvdbLanguageCode): ?array
    {
        $response = $this->request('GET', '/series/' . $tvdbSeriesId . '/translations/' . $tvdbLanguageCode);
        return !empty($response['data']) ? $response['data'] : null;
    }

    /**
     * A series typically has dozens of background images (artwork type 3)
     * - only the highest-scored one is kept; re-sorted here rather than
     * assuming TheTVDB's own ordering.
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

        // retry once with a forced fresh login, but only on a real 401 - a
        // legitimate 404 (e.g. a missing translation) isn't an auth failure
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
     * proxies through this app's own dev server address when IS_DEV is
     * true, breaking real third-party calls (curl error 7). Also sidesteps
     * Curl::get()'s GET+params bug (params land in CURLOPT_POSTFIELDS,
     * turning a GET into a silent POST) by baking query params into $url
     * instead.
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
        // Config::get() returns '' (not an array) when the config file
        // hasn't been copied from its .dist yet - guard explicitly to avoid
        // a confusing TypeError below
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

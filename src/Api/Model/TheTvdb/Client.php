<?php

namespace Api\Model\TheTvdb;

use Core\Utils\Config;
use RuntimeException;

class Client
{

    private const string BASE_URL = 'https://api4.thetvdb.com/v4';

    /**
     * artwork type 15 = "Background" for a movie - confirmed via
     * GET /artwork/types (recordType "movie"); NOT type 3, which is what
     * Api\Model\Series::sync()/getSeriesBackground() use for a series -
     * these two type ids are unrelated despite sharing the same English name
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
     * omitting $type from the request entirely (see performSearch()) returns
     * series and movies (and other TheTVDB record types) ranked together in
     * one list - confirmed empirically each result still carries its own
     * `type` field, so the caller can tell them apart without a second
     * lookup. Used by the app's single top-level search, unlike search()/
     * searchMovies() above which stay type-scoped for flows that only make
     * sense for one kind (e.g. adding a series to a user_list)
     */
    public function searchAll(string $query, int $page, string $tvdbLanguageCode): array
    {
        return $this->performSearch($query, $page, null, $tvdbLanguageCode);
    }

    /**
     * $tvdbLanguageCode picks each result's name/overview out of its own
     * inline `translations`/`overviews` maps (TheTVDB's search index already
     * returns every language it has for a result, confirmed empirically -
     * no separate per-result translation call needed here, unlike series/
     * episode/movie detail) - falls back to the result's own primary-
     * language name/overview if that specific language isn't in the map.
     * $type is TheTVDB's own search type ('series'/'movie'), or null to
     * search across every type at once (searchAll())
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
            // an unrestricted search (searchAll()) returns every TheTVDB
            // record type - company, person, etc. - not just series/movie;
            // confirmed empirically (e.g. "Encanto" also matches "Encanto
            // Enterprises", a company). The app's unified search only ever
            // knows how to display/open these two kinds, so anything else
            // is dropped here rather than leaking into the UI as a
            // mislabeled series
            $results = array_values(array_filter(
                $results,
                fn(array $result): bool => in_array($result['type'] ?? null, array('series', 'movie'), true)
            ));
        }

        foreach ($results as &$result) {
            $result['name']     = $result['translations'][$tvdbLanguageCode] ?? $result['name'] ?? null;
            $result['overview'] = $result['overviews'][$tvdbLanguageCode] ?? $result['overview'] ?? null;
            // renamed to match serie.image/movie.image - no background/
            // fanart field exists on a search result at all (confirmed
            // empirically), and fetching one would mean a separate
            // /artworks call per result; it only ever appears once a
            // series/movie is actually opened (Detail, .background)
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
     * /extended, not the base /series/{id} - needed for `genres` (confirmed
     * empirically absent from the base record, same as a movie's own base
     * record). Everything else Series::upsert() reads (name/slug/image/
     * firstAired/lastAired/averageRuntime/status) is present in the exact
     * same shape on both, confirmed empirically - unlike getMovie(), no
     * extra background/overview request is needed here since a series'
     * /extended response already includes both directly
     */
    public function getSeries(int $tvdbId): array
    {
        $response = $this->request('GET', '/series/' . $tvdbId . '/extended');
        return $response['data'] ?? array();
    }

    /**
     * a movie's own GET /movies/{id}/extended already returns its full
     * artworks list inline - confirmed empirically (57 artworks back for
     * "Encanto", including type 15 backgrounds) - so no separate background
     * request is needed, unlike getSeries()/getSeriesBackground(). It does
     * NOT include a top-level 'overview' though (only 'overviewTranslations',
     * a list of language codes) - confirmed empirically against the real API
     * - unlike a series' base record, which has one. A second request fills
     * it in from the movie's own original-language translation, the same
     * text 'overview' would have held had TheTVDB included it directly.
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
     * counterpart of getSeriesTranslation() - same shape ({name, overview,
     * language}), same null-on-no-translation behavior, confirmed empirically
     * against the real API (e.g. "Encanto" has a Spanish translation, no
     * Catalan one - a 404, not an error)
     */
    public function getMovieTranslation(int $tvdbId, string $tvdbLanguageCode): ?array
    {
        $response = $this->request('GET', '/movies/' . $tvdbId . '/translations/' . $tvdbLanguageCode);
        return !empty($response['data']) ? $response['data'] : null;
    }

    /**
     * follows every page - a single TVDB page tops out at 500 episodes,
     * which a long-running weekly/daily show (confirmed for real: "APM?"
     * 22 seasons, "30 minuts" 42 seasons) genuinely exceeds, silently
     * truncating the higher seasons when only page 0 was ever fetched (the
     * previous version of this method). Most shows still only need the one
     * request - this only pages further when TheTVDB's own `links.next`
     * says there's more.
     */
    public function getSeriesEpisodes(int $tvdbSeriesId, string $seasonType = 'official'): array
    {
        return $this->getAllEpisodePages('/series/' . $tvdbSeriesId . '/episodes/' . $seasonType);
    }

    /**
     * unlike getSeriesTranslation() (one series-level record), this returns
     * *every* episode of the series translated into $tvdbLanguageCode in a
     * single page - confirmed empirically (149 episodes back for a 149-
     * episode series, same as the untranslated getSeriesEpisodes() call),
     * so no need to fetch translations per-episode. Still paginated the
     * same way as getSeriesEpisodes() above for the same reason.
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

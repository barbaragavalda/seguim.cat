<?php

namespace Tests\Fixtures;

use Api\Model\TheTvdb\Client;

/**
 * Skips Client::__construct() entirely (no Config/cache-dir setup needed -
 * every method this fixture is used for is overridden below).
 */
final class FakeTvdbClient extends Client
{

    /** @var array<string, array{results: array<int, array<string, mixed>>, hasMore: bool}> */
    private array $searchResponses = array();

    /** @var array<int, bool> */
    private array $existingIds = array();

    public function __construct()
    {
    }

    public function queueSearch(string $query, array $results): void
    {
        $this->searchResponses[$query] = array('results' => $results, 'hasMore' => false);
    }

    public function markExisting(int $tvdbId): void
    {
        $this->existingIds[$tvdbId] = true;
    }

    public function search(string $query, int $page, string $tvdbLanguageCode): array
    {
        return $this->searchResponses[$query] ?? array('results' => array(), 'hasMore' => false);
    }

    public function searchMovies(string $query, int $page, string $tvdbLanguageCode): array
    {
        return $this->searchResponses[$query] ?? array('results' => array(), 'hasMore' => false);
    }

    public function getSeries(int $tvdbId): array
    {
        return isset($this->existingIds[$tvdbId]) ? array('id' => $tvdbId) : array();
    }

    public function getMovie(int $tvdbId): array
    {
        return isset($this->existingIds[$tvdbId]) ? array('id' => $tvdbId) : array();
    }

}

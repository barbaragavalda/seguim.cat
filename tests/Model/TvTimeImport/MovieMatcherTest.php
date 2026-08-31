<?php

namespace Tests\Model\TvTimeImport;

use Api\Model\TvTimeImport\MovieMatcher;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FakeTvdbClient;

final class MovieMatcherTest extends TestCase
{

    /** @param array<string, mixed> $overrides */
    private function candidate(array $overrides = array()): array
    {
        return array_merge(
            array('tvdb_id' => 1, 'name' => 'Mamma Mia!', 'year' => '2008', 'translations' => array()),
            $overrides
        );
    }

    public function testSingleExactMatchIsTrustedEvenWithoutAYear(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Mamma Mia!', array($this->candidate()));
        $client->markExisting(1);

        $result = (new MovieMatcher($client))->match('Mamma Mia!', null);

        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $result['tvdb_id']);
    }

    public function testUniqueYearMatchDisambiguatesMultipleCandidates(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Mamma Mia!', array(
            $this->candidate(array('tvdb_id' => 1, 'year' => '2008')),
            $this->candidate(array('tvdb_id' => 2, 'year' => '2018')),
        ));
        $client->markExisting(1);
        $client->markExisting(2);

        $result = (new MovieMatcher($client))->match('Mamma Mia!', '2008');

        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $result['tvdb_id']);
    }

    public function testYearWithinOneYearToleranceStillDisambiguates(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Mamma Mia!', array(
            $this->candidate(array('tvdb_id' => 1, 'year' => '2008')),
            $this->candidate(array('tvdb_id' => 2, 'year' => '2018')),
        ));
        $client->markExisting(1);
        $client->markExisting(2);

        // TV Time's release_date is occasionally off by one - see class docblock
        $result = (new MovieMatcher($client))->match('Mamma Mia!', '2009');

        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $result['tvdb_id']);
    }

    public function testAmbiguousYearFallsBackToAmbiguousStatus(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Untitled Remake', array(
            $this->candidate(array('tvdb_id' => 1, 'name' => 'Untitled Remake', 'year' => '2008')),
            $this->candidate(array('tvdb_id' => 2, 'name' => 'Untitled Remake', 'year' => '2009')),
        ));
        $client->markExisting(1);
        $client->markExisting(2);

        // both candidates are within +-1 of the expected year - can't disambiguate
        $result = (new MovieMatcher($client))->match('Untitled Remake', '2008.5');

        $this->assertSame('ambiguous', $result['status']);
    }

    public function testFuzzyMatchSkipsYearDisambiguationEntirely(): void
    {
        $client = new FakeTvdbClient();
        // no exact-name hit, only a fuzzy word-overlap one - year disambiguation
        // is deliberately never applied to a fuzzy result set (see class docblock)
        $client->queueSearch('Mamma Mia', array(
            $this->candidate(array('tvdb_id' => 1, 'name' => 'Mamma Mia Here We Go Again', 'year' => '2008')),
        ));
        $client->markExisting(1);

        $result = (new MovieMatcher($client))->match('Mamma Mia', '2008');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['tvdb_id']);
    }

    public function testNoCandidatesIsNoMatch(): void
    {
        $client = new FakeTvdbClient();

        $result = (new MovieMatcher($client))->match('Some Obscure Movie', '2020');

        $this->assertSame('no_match', $result['status']);
    }

}

<?php

namespace Tests\Model\TvTimeImport;

use Api\Model\TvTimeImport\SeriesMatcher;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FakeTvdbClient;

final class SeriesMatcherTest extends TestCase
{

    /** @param array<string, mixed> $overrides */
    private function candidate(array $overrides = array()): array
    {
        return array_merge(
            array('tvdb_id' => 1, 'name' => 'Lost', 'year' => '2004', 'translations' => array()),
            $overrides
        );
    }

    public function testSingleExactMatchIsTrustedDirectly(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Lost', array($this->candidate()));
        $client->markExisting(1);

        $result = (new SeriesMatcher($client))->match('Lost');

        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $result['tvdb_id']);
    }

    public function testNoResultsAnywhereIsNoMatch(): void
    {
        $client = new FakeTvdbClient();

        $result = (new SeriesMatcher($client))->match('Some Obscure Show');

        $this->assertSame('no_match', $result['status']);
        $this->assertNull($result['tvdb_id']);
        $this->assertSame(array(), $result['candidates']);
    }

    public function testMultipleLiveExactMatchesAreAmbiguous(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Shameless', array(
            $this->candidate(array('tvdb_id' => 10, 'name' => 'Shameless')),
            $this->candidate(array('tvdb_id' => 20, 'name' => 'Shameless')),
        ));
        $client->markExisting(10);
        $client->markExisting(20);

        $result = (new SeriesMatcher($client))->match('Shameless');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertCount(2, $result['candidates']);
    }

    public function testDeadCandidateIsFilteredOutBeforeDisambiguating(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Prison Break', array(
            $this->candidate(array('tvdb_id' => 75340, 'name' => 'Prison Break')),
            $this->candidate(array('tvdb_id' => 360115, 'name' => 'Prison Break')),
        ));
        // only the "real" id still resolves on TheTVDB - the other is a
        // stale search-index entry for a merged/deleted id (see class docblock)
        $client->markExisting(360115);

        $result = (new SeriesMatcher($client))->match('Prison Break');

        $this->assertSame('matched', $result['status']);
        $this->assertSame(360115, $result['tvdb_id']);
    }

    public function testAllCandidatesDeadIsNoMatch(): void
    {
        $client = new FakeTvdbClient();
        $client->queueSearch('Ghost Show', array(
            $this->candidate(array('tvdb_id' => 1)),
            $this->candidate(array('tvdb_id' => 2)),
        ));
        // neither id resolves - not marked existing

        $result = (new SeriesMatcher($client))->match('Ghost Show');

        $this->assertSame('no_match', $result['status']);
    }

    public function testFallbackQuerySplitsOnColonWhenPlainSearchMisses(): void
    {
        $client = new FakeTvdbClient();
        // the plain search for the full title returns nothing; searching just
        // "Continuum" surfaces the same record, whose own name still matches
        // the full original title exactly (fallback only widens the search,
        // matching itself always checks against the original title - see
        // SeriesMatcher::findCandidates()'s docblock)
        $client->queueSearch('Continuum: Rebirth', array());
        $client->queueSearch('Continuum', array($this->candidate(array('tvdb_id' => 5, 'name' => 'Continuum: Rebirth'))));
        $client->markExisting(5);

        $result = (new SeriesMatcher($client))->match('Continuum: Rebirth');

        $this->assertSame('matched', $result['status']);
        $this->assertSame(5, $result['tvdb_id']);
    }

    public function testFuzzyMatchIsNeverAutoTrustedEvenWithASingleCandidate(): void
    {
        $client = new FakeTvdbClient();
        // no exact-name hit anywhere, but "Breaking Bad Sequel" shares more
        // than half its words with the target "breaking bad"
        $client->queueSearch('Breaking Bad', array(
            $this->candidate(array('tvdb_id' => 99, 'name' => 'Breaking Bad Sequel')),
        ));
        $client->markExisting(99);

        $result = (new SeriesMatcher($client))->match('Breaking Bad');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['tvdb_id']);
        $this->assertCount(1, $result['candidates']);
    }

    public function testCandidateListIsCappedAtFive(): void
    {
        $client = new FakeTvdbClient();
        $results = array();
        for ($i = 1; $i <= 8; $i++) {
            $results[] = $this->candidate(array('tvdb_id' => $i, 'name' => 'Duplicate Show'));
            $client->markExisting($i);
        }
        $client->queueSearch('Duplicate Show', $results);

        $result = (new SeriesMatcher($client))->match('Duplicate Show');

        $this->assertSame('ambiguous', $result['status']);
        $this->assertCount(5, $result['candidates']);
    }

}

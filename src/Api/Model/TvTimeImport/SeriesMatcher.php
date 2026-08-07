<?php

namespace Api\Model\TvTimeImport;

use Api\Model\TheTvdb\Client;

/**
 * Resolves a show name to a TheTVDB id via name search - used only when the
 * export's own tv_show_id no longer resolves (TheTVDB renumbers/merges ids
 * over time, e.g. the v3->v4 migration; confirmed against 22 real
 * shows_failed entries, e.g. Prison Break 75340 vs 360115).
 *
 * Mirrors MovieMatcher: never guess an ambiguous same-titled result, queue
 * it for the user instead (see MovieMatcher's docblock for the fuller
 * rationale and the fallback/fuzzy logic duplicated below). Simpler in one
 * way: a series has no reliable release year, so a collision can never be
 * auto-disambiguated by year the way a movie's can.
 */
final class SeriesMatcher
{

    /**
     * $preferredLanguage only affects a candidate's display name - see
     * MovieMatcher's docblock on why matching itself doesn't need it.
     */
    public function __construct(private readonly Client $client, private readonly string $preferredLanguage = 'eng')
    {
    }

    /**
     * @return array{
     *     status: 'matched'|'ambiguous'|'no_match',
     *     tvdb_id: ?int,
     *     candidates: array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}>
     * }
     */
    public function match(string $name): array
    {
        $found      = $this->findCandidates($name);
        $candidates = $found['items'];
        $isFuzzy    = $found['fuzzy'];

        if (count($candidates) === 0) {
            return array('status' => 'no_match', 'tvdb_id' => null, 'candidates' => array());
        }

        // a single exact-name hit is trusted (collision + single exact
        // result is rare); a dead id here is still caught by
        // Processor::processRenamedShow()'s follow-up sync(). Never applies
        // to a fuzzy hit, which always falls through to the resolution screen.
        if (!$isFuzzy && count($candidates) === 1) {
            return array(
                'status'     => 'matched',
                'tvdb_id'    => (int) $candidates[0]['tvdb_id'],
                'candidates' => array(),
            );
        }

        // TheTVDB's search index can lag behind a merge/deletion (e.g.
        // "A Teacher" still search-matches a tvdb_id that 404s directly) -
        // drop dead candidates before ever presenting them as a choice.
        $liveCandidates = array_values(array_filter(
            $candidates,
            fn(array $c): bool => $this->existsOnTvdb((int) $c['tvdb_id'])
        ));

        if (count($liveCandidates) === 0) {
            return array('status' => 'no_match', 'tvdb_id' => null, 'candidates' => array());
        }
        if (!$isFuzzy && count($liveCandidates) === 1) {
            return array(
                'status'     => 'matched',
                'tvdb_id'    => (int) $liveCandidates[0]['tvdb_id'],
                'candidates' => array(),
            );
        }

        return array(
            'status'     => 'ambiguous',
            'tvdb_id'    => null,
            'candidates' => $this->toCandidateList($liveCandidates),
        );
    }

    /**
     * Only called for a multi-result search; the common single-hit case
     * skips this extra round trip (see match()).
     */
    private function existsOnTvdb(int $tvdbId): bool
    {
        return !empty($this->client->getSeries($tvdbId));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, fuzzy: bool}
     */
    private function findCandidates(string $name): array
    {
        $target = self::normalize($name);

        $results = $this->client->search($name, 0, 'eng')['results'] ?? array();
        $matches = self::filterMatchingName($results, $target);
        if (count($matches) > 0) {
            return array('items' => $matches, 'fuzzy' => false);
        }

        foreach (self::fallbackQueries($name) as $fallbackQuery) {
            $fallbackResults = $this->client->search($fallbackQuery, 0, 'eng')['results'] ?? array();
            $fallbackMatches = self::filterMatchingName($fallbackResults, $target);
            if (count($fallbackMatches) > 0) {
                return array('items' => $fallbackMatches, 'fuzzy' => false);
            }
        }

        // no exact match anywhere - fall back to fuzzy ranking (see MovieMatcher::rankFuzzyMatches())
        $fuzzyMatches = self::rankFuzzyMatches($results, $target);
        if (count($fuzzyMatches) > 0) {
            return array('items' => $fuzzyMatches, 'fuzzy' => true);
        }

        return array('items' => array(), 'fuzzy' => false);
    }

    /**
     * Identical to MovieMatcher::rankFuzzyMatches() - see that docblock;
     * kept as its own copy for the same reason as fallbackQueries() below.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function rankFuzzyMatches(array $results, string $target): array
    {
        $targetWords = array_values(array_filter(explode(' ', $target)));
        if (count($targetWords) === 0) {
            return array();
        }

        $scored = array();
        foreach ($results as $result) {
            $candidateName = self::normalize($result['name'] ?? '');
            if ($candidateName === '') {
                continue;
            }
            $matchedWords = array_filter($targetWords, fn(string $word): bool => str_contains($candidateName, $word));
            $score        = count($matchedWords) / count($targetWords);
            if ($score > 0.5) {
                $scored[] = array('result' => $result, 'score' => $score);
            }
        }

        usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice(array_column($scored, 'result'), 0, 10);
    }

    /**
     * Identical to MovieMatcher::fallbackQueries() (see its docblock for
     * why these loosenings) - kept as its own copy rather than shared, to
     * avoid touching the already-verified-in-production original.
     *
     * @return array<int, string>
     */
    private static function fallbackQueries(string $name): array
    {
        $queries = array();

        foreach (array(':', ' - ', '–') as $separator) {
            if (!str_contains($name, $separator)) {
                continue;
            }
            [$before, $after] = array_pad(explode($separator, $name, 2), 2, '');
            $queries[] = trim($before);
            if (trim($after) !== '') {
                $queries[] = trim($after);
            }
        }

        $withoutStopwords = preg_replace('/\b(and|the)\b/i', '', $name) ?? $name;
        $withoutStopwords = trim(preg_replace('/\s+/', ' ', $withoutStopwords) ?? $withoutStopwords);
        if ($withoutStopwords !== '' && $withoutStopwords !== $name) {
            $queries[] = $withoutStopwords;
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function filterMatchingName(array $results, string $target): array
    {
        return array_values(array_filter(
            $results,
            fn(array $result): bool => self::hasMatchingName($result, $target)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array{tvdb_id: int, name: string, year: ?string, image: ?string}>
     */
    private function toCandidateList(array $candidates): array
    {
        // capped at 5 - see MovieMatcher::toCandidateList()'s own reasoning
        return array_map(
            fn(array $c): array => array(
                'tvdb_id' => (int) $c['tvdb_id'],
                'name'    => $this->displayName($c),
                'year'    => $c['year'] ?? null,
                'image'   => $c['image'] ?? null,
            ),
            array_slice($candidates, 0, 5)
        );
    }

    /**
     * see MovieMatcher::displayName()'s own docblock
     *
     * @param array<string, mixed> $candidate
     */
    private function displayName(array $candidate): string
    {
        $translations = $candidate['translations'] ?? array();
        return $translations[$this->preferredLanguage]
            ?? $translations['eng']
            ?? $candidate['name']
            ?? '';
    }

    private static function hasMatchingName(array $result, string $target): bool
    {
        $candidateNames   = array_values($result['translations'] ?? array());
        $candidateNames[] = $result['name'] ?? null;

        foreach ($candidateNames as $candidateName) {
            if ($candidateName !== null && self::normalize($candidateName) === $target) {
                return true;
            }
        }
        return false;
    }

    private static function normalize(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $ascii = strtolower($ascii !== false ? $ascii : $name);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return trim($ascii);
    }

}

<?php

namespace Api\Model\TvTimeImport;

use Api\Model\TheTvdb\Client;

/**
 * Resolves a TV Time show name to a TheTVDB series id via name search, used
 * only as a fallback when the export's own tv_show_id no longer resolves on
 * TheTVDB (Api\Model\Series::sync() returns empty for it). TheTVDB
 * periodically renumbers/merges series ids (the v3->v4 migration and
 * ordinary duplicate cleanups), leaving TV Time's old export pointing at a
 * dead id while the same series is findable by name under a new one -
 * confirmed empirically against the user's own real 22 shows_failed entries
 * (same phenomenon documented in the open-source "rewatch" tracker's own
 * code comments, e.g. Prison Break 75340 vs 360115).
 *
 * Mirrors MovieMatcher's own reasoning (never guess a same-titled ambiguous
 * result - queue it for the user instead - see its docblock for the fuller
 * rationale and the fallback-query retry logic duplicated below), but
 * simpler in one way: TV Time's export carries no reliable release year for
 * a series the way it does for a movie, so a same-titled collision can only
 * ever come back ambiguous, never auto-disambiguated by year. Also mirrors
 * its fuzzy word-overlap fallback (see MovieMatcher's own docblock) for
 * when even the loosened exact-name queries find nothing - never trusted as
 * a single confident match either, same $isFuzzy reasoning as there.
 */
final class SeriesMatcher
{

    /**
     * $preferredLanguage (TheTVDB's own 3-letter code) is only used for a
     * candidate's own *display* name - see MovieMatcher's own docblock on
     * why matching itself doesn't need it
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

        // a single exact-name hit is trusted, same as MovieMatcher::match()
        // for the same reason (a real title collision AND TheTVDB returning
        // only one of them as an exact-name result is rare) - a dead id here
        // still gets caught right after by Processor::processRenamedShow()'s
        // own follow-up Series::sync() call, so it doesn't need validating
        // against TheTVDB again here. Never applies to a fuzzy hit though
        // (see this class' own docblock) - that always falls through to the
        // resolution screen below, even alone.
        if (!$isFuzzy && count($candidates) === 1) {
            return array(
                'status'     => 'matched',
                'tvdb_id'    => (int) $candidates[0]['tvdb_id'],
                'candidates' => array(),
            );
        }

        // more than one same-named result - TheTVDB's own search index can
        // lag behind a merge/deletion on the actual record (confirmed
        // empirically: e.g. "A Teacher"/"Wild Wild Country" each still
        // search-match a tvdb_id that 404s on a direct GET /series/{id}), so
        // drop anything dead before ever presenting it as a choice - a
        // resolution-screen candidate the user picks should always actually
        // resolve, never fail silently
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
     * only called once a name search already came back with more than one
     * same-named result (see match()) - the common single-hit case is left
     * to Processor's own follow-up sync() call instead, to avoid an extra
     * TheTVDB round trip for every confidently-matched show
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

        // nothing came back as an exact name match anywhere - see
        // MovieMatcher::rankFuzzyMatches()'s own docblock, applied here
        // against the plain search's own raw results only
        $fuzzyMatches = self::rankFuzzyMatches($results, $target);
        if (count($fuzzyMatches) > 0) {
            return array('items' => $fuzzyMatches, 'fuzzy' => true);
        }

        return array('items' => array(), 'fuzzy' => false);
    }

    /**
     * identical logic to MovieMatcher::rankFuzzyMatches() - see that
     * class' own docblock; kept as its own copy for the same reason
     * fallbackQueries() below is
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
     * identical logic to MovieMatcher::fallbackQueries() - see that class'
     * own docblock for why these specific loosenings (colon/dash split,
     * dropping "and"/"the") - kept as its own copy here rather than a shared
     * helper since the two matchers' candidate shapes/search scopes differ
     * and MovieMatcher is already verified in production; not worth the risk
     * of touching it to deduplicate ~15 lines
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

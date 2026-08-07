<?php

namespace Api\Model\TvTimeImport;

use Api\Model\TheTvdb\Client;

/**
 * Resolves a movie entry (name + optional expected release year) to a
 * TheTVDB movie id via search. Unlike series, TV Time's export has no id
 * for movies at all - just movie_name + release_date - so this is a
 * genuine name-based match, with real (if rare) risk of a wrong pick.
 * Confirmed against a real 120-title sample: ~79% matched with full
 * confidence, ~16% ambiguous, the rest no result or a wrong release year
 * on an otherwise-unique title (TV Time's release_date isn't always the
 * original release year).
 *
 * Anything not a single confident match comes back 'ambiguous' (up to 5
 * candidates) rather than 'no_match' whenever at least one same-name
 * result exists - never auto-guessed (a same-titled remake picked wrong
 * would be a silently wrong watch history); Processor::processMovies()
 * persists these as MovieImportPending rows for the user to resolve by
 * hand.
 *
 * TheTVDB's search sometimes returns nothing for a title that genuinely
 * exists under that exact name (e.g. "Harry Potter and the Half-Blood
 * Prince" returns zero results) - findCandidates() retries with loosened
 * queries (colon/dash split, dropping "and"/"the") whenever the first
 * search is empty; every fallback result is still filtered by an exact
 * match against the *original* title, so this can only find a missed
 * title, never introduce a wrong one.
 *
 * When even loosened queries find no exact-name hit, this falls back to a
 * fuzzy word-overlap heuristic (>50% of words in common, against the plain
 * search's raw results) for a real match whose title text isn't a literal
 * match (different subtitle, punctuation, missing translation). A fuzzy
 * hit is never trusted automatically (see $isFuzzy in match()) - it always
 * goes through the resolution screen, even alone.
 */
final class MovieMatcher
{

    /**
     * $preferredLanguage only affects a candidate's display name
     * (toCandidateList()) - matching itself already checks every
     * translation Client::performSearch() returns, regardless of language.
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
    public function match(string $name, ?string $expectedYear): array
    {
        $found      = $this->findCandidates($name);
        $candidates = $found['items'];
        $isFuzzy    = $found['fuzzy'];

        if (count($candidates) === 0) {
            return array('status' => 'no_match', 'tvdb_id' => null, 'candidates' => array());
        }

        // a single exact-title hit is trusted even without a year match
        // (collision + single exact result is rare, and release_date is
        // occasionally wrong - see class docblock); a dead id here is still
        // caught by Processor::processMovies()'s follow-up sync(). Never
        // applies to a fuzzy hit, which always falls through to the
        // resolution screen.
        if (!$isFuzzy && count($candidates) === 1) {
            return array(
                'status'     => 'matched',
                'tvdb_id'    => (int) $candidates[0]['tvdb_id'],
                'candidates' => array(),
            );
        }

        // TheTVDB's search index can lag behind a merge/deletion (see
        // SeriesMatcher's docblock) - drop dead candidates before
        // disambiguating by year or presenting them as a choice.
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

        // only a uniquely-matching release year can disambiguate further,
        // skipped for a fuzzy result set - stacking two soft heuristics
        // (fuzzy name + loose year) would compound their risk, and
        // release_date is itself sometimes wrong (see class docblock)
        if (!$isFuzzy && $expectedYear !== null) {
            $yearMatches = array_values(array_filter(
                $liveCandidates,
                fn(array $c): bool => !empty($c['year']) && abs((int) $c['year'] - (int) $expectedYear) <= 1
            ));
            if (count($yearMatches) === 1) {
                return array(
                    'status'     => 'matched',
                    'tvdb_id'    => (int) $yearMatches[0]['tvdb_id'],
                    'candidates' => array(),
                );
            }
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
        return !empty($this->client->getMovie($tvdbId));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, fuzzy: bool}
     */
    private function findCandidates(string $name): array
    {
        $target = self::normalize($name);

        $results = $this->client->searchMovies($name, 0, 'eng')['results'] ?? array();
        $matches = self::filterMatchingName($results, $target);
        if (count($matches) > 0) {
            return array('items' => $matches, 'fuzzy' => false);
        }

        foreach (self::fallbackQueries($name) as $fallbackQuery) {
            $fallbackResults = $this->client->searchMovies($fallbackQuery, 0, 'eng')['results'] ?? array();
            $fallbackMatches = self::filterMatchingName($fallbackResults, $target);
            if (count($fallbackMatches) > 0) {
                return array('items' => $fallbackMatches, 'fuzzy' => false);
            }
        }

        // no exact match anywhere - fuzzy fallback against the plain search's
        // raw results only (not every fallback query's, diminishing returns)
        $fuzzyMatches = self::rankFuzzyMatches($results, $target);
        if (count($fuzzyMatches) > 0) {
            return array('items' => $fuzzyMatches, 'fuzzy' => true);
        }

        return array('items' => array(), 'fuzzy' => false);
    }

    /**
     * >50% of $target's own words found in a result's name, scored against
     * $target's word count (not the candidate's, to avoid a short candidate
     * trivially clearing 50%). Ranked best-first, capped at 10 here since
     * existsOnTvdb() costs a round trip each - toCandidateList()'s final
     * slice(0, 5) still picks the best 5 that are actually alive.
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
     * Looser retry queries: splitting on a colon/dash covers a "Title:
     * Subtitle" shape (try each half alone); dropping "and"/"the" covers
     * "Harry Potter and the X"-style titles. Results still have to match
     * the original $name exactly (see findCandidates()).
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
        // capped at 5 - showing every same-titled result (a dozen+ for a common
        // title) would be useless; the search response is already relevance-ranked
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
     * User's language first, English next, then TheTVDB's default name.
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

    /**
     * ASCII-folds, lowercases and collapses punctuation/whitespace so
     * "Mamma Mia!" and an accented title compare equal to their plain form.
     */
    private static function normalize(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $ascii = strtolower($ascii !== false ? $ascii : $name);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return trim($ascii);
    }

}

<?php

namespace Api\Model\TvTimeImport;

use Api\Model\TheTvdb\Client;

/**
 * Resolves a TV Time movie entry (name + optional expected release year) to
 * a TheTVDB movie id via search. Unlike series (tv_show_id is TheTVDB's own
 * id directly), TV Time's export has no id for movies at all - just
 * movie_name + release_date - so this is a genuine name-based match, with
 * real (if rare) risk of a wrong pick.
 *
 * Confirmed empirically against the user's own real export (a random
 * 120-title sample): ~79% matched with full confidence (a single exact
 * title match, whether or not the release year confirms it - see match()'s
 * own reasoning below), ~16% were ambiguous (more than one movie shares that
 * exact title and the year can't disambiguate them, or there's no year at
 * all to try), and the rest returned no result or a genuinely wrong year on
 * an otherwise-unique title (TV Time's own release_date isn't always the
 * original release year - confirmed for one title where it was off by
 * decades).
 *
 * Anything not a single confident match comes back as 'ambiguous' (with up
 * to 5 candidates) rather than 'no_match' whenever there's at least one
 * same-name TheTVDB result - never guessed automatically (a same-titled
 * remake picked wrong would be a silently incorrect watch history), but also
 * never just dropped: Processor::processMovies() persists these as
 * Api\Model\MovieImportPending rows for the user to resolve by hand later
 * (pick the right poster, or dismiss) - same pattern several other TV Time
 * migration tools (e.g. the open-source "rewatch" tracker) converged on
 * independently, since a human glancing at a poster+year disambiguates
 * instantly in a way no automated heuristic can guarantee.
 *
 * TheTVDB's own search endpoint sometimes returns *nothing at all* for a
 * long exact title that genuinely exists under that literal name - confirmed
 * empirically ("Harry Potter and the Half-Blood Prince" as a query returns
 * zero results, even though TheTVDB has a movie with exactly that name;
 * dropping "and"/"the", or querying just the part before/after a colon or
 * dash, reliably surfaces it instead). findCandidates() retries with those
 * loosened queries whenever the first search comes back empty - this can
 * only ever *find* a title TheTVDB's own search missed, never introduce a
 * wrong one, since every fallback attempt is still filtered by an exact
 * match against the *original* full title, not the shortened query used to
 * ask TheTVDB.
 *
 * When even the loosened queries find no *exact*-name hit, this falls back
 * once more to a fuzzy word-overlap heuristic (inspired by the open-source
 * TvTimeToTrakt's own Title.matches() - a >50% word-overlap rule it reports
 * "seems to improve automation, and reduce manual selection") against the
 * plain search's own raw results: TheTVDB's search can return a real match
 * whose own title text simply isn't a literal match (a different subtitle,
 * punctuation, or a translation TheTVDB doesn't carry), which the exact
 * pass above would otherwise drop as 'no_match' with nothing to show at
 * all. Unlike an exact match, a fuzzy hit is never trusted automatically -
 * see match()'s own $isFuzzy handling - a coincidental word overlap is a
 * real (if smaller) risk of a wrong pick, so it always goes through the
 * resolution screen even when it's the only candidate found.
 */
final class MovieMatcher
{

    /**
     * $preferredLanguage (TheTVDB's own 3-letter code, e.g. 'cat'/'spa') is
     * only used for a candidate's own *display* name (toCandidateList()
     * below) - matching itself already checks every translation TheTVDB
     * has for a result regardless of this, since Client::performSearch()
     * always returns the full `translations` map alongside whichever
     * single language was requested (see its own docblock)
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

        // a single exact-title hit is trusted even without a year match - a
        // real title collision AND TheTVDB returning only one of them as an
        // "exact name" result is rare, and TV Time's own release_date is
        // occasionally wrong for an otherwise correctly-matched title (see
        // this class' own docblock) - a dead id here still gets caught right
        // after by Processor::processMovies()'s own follow-up Movie::sync()
        // call, so it doesn't need validating against TheTVDB again here.
        // Never applies to a fuzzy hit though (see this class' own
        // docblock on $isFuzzy) - that always falls through to the
        // resolution screen below, even alone.
        if (!$isFuzzy && count($candidates) === 1) {
            return array(
                'status'     => 'matched',
                'tvdb_id'    => (int) $candidates[0]['tvdb_id'],
                'candidates' => array(),
            );
        }

        // more than one movie shares this exact title - TheTVDB's own search
        // index can lag behind a merge/deletion on the actual record
        // (confirmed empirically, same phenomenon as SeriesMatcher's own
        // docblock), so drop anything dead before disambiguating by year or
        // ever presenting it as a choice - a resolution-screen candidate the
        // user picks should always actually resolve, never fail silently
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

        // only a matching release year can disambiguate further, and only
        // if it does so uniquely - skipped entirely for a fuzzy result set:
        // stacking two soft heuristics (fuzzy name + a loose year window)
        // to auto-apply would compound their risk, and TV Time's own
        // release_date is itself sometimes wrong (see this class' own
        // docblock), which could filter out the actually-correct fuzzy
        // candidate
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
     * only called once a title search already came back with more than one
     * same-named result (see match()) - the common single-hit case is left
     * to Processor's own follow-up sync() call instead, to avoid an extra
     * TheTVDB round trip for every confidently-matched movie
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

        // nothing came back as an exact name match anywhere - see this
        // class' own docblock on the fuzzy fallback tried here, against the
        // plain search's own raw results only (not every fallback query's -
        // diminishing returns for meaningfully more TheTVDB calls)
        $fuzzyMatches = self::rankFuzzyMatches($results, $target);
        if (count($fuzzyMatches) > 0) {
            return array('items' => $fuzzyMatches, 'fuzzy' => true);
        }

        return array('items' => array(), 'fuzzy' => false);
    }

    /**
     * >50% of $target's own words found (as a substring) somewhere in a
     * result's name - same threshold TvTimeToTrakt's own Title.matches()
     * uses, applied here against $target's word count rather than the
     * candidate's own (simpler, and avoids that a short candidate title
     * trivially clears 50% against a long target). Ranked best-first and
     * capped at 10 here (existsOnTvdb() calls are one TheTVDB round trip
     * each, done later in match() only after this) - toCandidateList()'s
     * own final slice(0, 5) still picks the best 5 that are actually alive,
     * not just the best 5 raw-ranked ones.
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
     * Looser queries to retry TheTVDB's search with when the exact title
     * finds nothing - see this class' own docblock. Splitting on a colon/
     * dash covers a "Title: Subtitle" shape (try each half alone); dropping
     * "and"/"the" covers titles like "Harry Potter and the X" specifically.
     * Every candidate found this way still has to match the *original*
     * $name exactly (see findCandidates()) before it counts for anything.
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
        // capped at 5 - a resolution screen showing every same-titled result
        // TheTVDB has (sometimes a dozen+ for a very common title) would be
        // useless; the search response is already relevance-ranked, so the
        // first few are overwhelmingly the ones a real person would mean
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
     * The user's own language first, English next, and only then whatever
     * TheTVDB calls this result's own primary/default name - a candidate
     * poster's title should read in the language the user actually reads
     * the app in whenever TheTVDB has a translation for it
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
     * "Mamma Mia!" and "mamma mia" (or an accented title against its plain
     * transliteration) compare equal - same normalization confirmed
     * empirically against the real export
     */
    private static function normalize(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $ascii = strtolower($ascii !== false ? $ascii : $name);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return trim($ascii);
    }

}

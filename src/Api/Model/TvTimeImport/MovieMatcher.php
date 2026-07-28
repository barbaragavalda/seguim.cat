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
 * decades). Anything not clearly resolved here is skipped and reported
 * rather than risking a wrong match - see Processor::processMovies()
 */
final class MovieMatcher
{

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return int|null the matched TheTVDB movie id, or null if there's no
     *                   single confident match
     */
    public function match(string $name, ?string $expectedYear): ?int
    {
        $results = $this->client->searchMovies($name, 0, 'eng')['results'] ?? array();

        $target     = self::normalize($name);
        $candidates = array_values(array_filter(
            $results,
            fn(array $result): bool => self::hasMatchingName($result, $target)
        ));

        if (count($candidates) === 0) {
            return null;
        }
        // a single exact-title hit is trusted even without a year match - a
        // real title collision AND TheTVDB returning only one of them as an
        // "exact name" result is rare, and TV Time's own release_date is
        // occasionally wrong for an otherwise correctly-matched title (see
        // this class' own docblock)
        if (count($candidates) === 1) {
            return (int) $candidates[0]['tvdb_id'];
        }

        // more than one movie shares this exact title - only a matching
        // release year can disambiguate, and only if it does so uniquely
        if ($expectedYear === null) {
            return null;
        }
        $yearMatches = array_values(array_filter(
            $candidates,
            fn(array $c): bool => !empty($c['year']) && abs((int) $c['year'] - (int) $expectedYear) <= 1
        ));

        return count($yearMatches) === 1 ? (int) $yearMatches[0]['tvdb_id'] : null;
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

<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Fuzzy matching for typo suggestions using Levenshtein distance.
 * Suggests closest match when distance <= 2.
 */
final class TypoIntelligence
{
    private const MAX_DISTANCE = 2;

    /**
     * Find closest match from candidates. Returns null if no match within threshold.
     *
     * @param  string[]  $candidates
     */
    public static function suggest(string $input, array $candidates): ?string
    {
        if (empty($candidates)) {
            return null;
        }

        $input = strtolower($input);
        $best = null;
        $bestDistance = self::MAX_DISTANCE + 1;

        foreach ($candidates as $candidate) {
            $c = strtolower((string) $candidate);
            if ($c === $input) {
                return $candidate;
            }
            $d = levenshtein($input, $c);
            if ($d <= self::MAX_DISTANCE && $d < $bestDistance) {
                $bestDistance = $d;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Common SQL keyword typos and corrections.
     *
     * @return array<string, string>
     */
    public static function keywordTypos(): array
    {
        return [
            'SELEC' => 'SELECT',
            'FORM' => 'FROM',
            'WERE' => 'WHERE',
            'TABLE' => 'TABLE',
            'JOIN' => 'JOIN',
            'ORDE' => 'ORDER',
            'GROP' => 'GROUP',
            'BY' => 'BY',
            'LIMT' => 'LIMIT',
            'OFFSET' => 'OFFSET',
            'UNION' => 'UNION',
            'INNERJOIN' => 'INNER JOIN',
            'LEFTJOIN' => 'LEFT JOIN',
            'RIGHTJOIN' => 'RIGHT JOIN',
        ];
    }

    /**
     * Check if input resembles a known keyword typo.
     */
    public static function suggestKeyword(string $token): ?string
    {
        $upper = strtoupper(trim($token));

        foreach (self::keywordTypos() as $typo => $correct) {
            if (levenshtein($upper, $typo) <= self::MAX_DISTANCE || $upper === $typo) {
                return $correct;
            }
        }

        return null;
    }
}

<?php

namespace App\Tools;

use Illuminate\Support\Str;

/**
 * Company-name normalisation shared by the news on-topic gate and the news query
 * builder: ASCII-folded lowercase text, distinctive tokens with Lithuanian legal
 * forms dropped, and an all-tokens-present check.
 */
final class CompanyNameTokens
{
    /** Lithuanian legal-form tokens dropped from name matching. */
    private const LEGAL_SUFFIXES = ['uab', 'mb', 'vsi', 'ii', 'ab', 'vso', 'ke', 'tub'];

    public static function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** @return array<int, string> */
    public static function tokens(string $name): array
    {
        $tokens = array_filter(
            explode(' ', self::normalize($name)),
            static fn (string $t): bool => mb_strlen($t) >= 3 && ! in_array($t, self::LEGAL_SUFFIXES, true),
        );

        return array_values($tokens);
    }

    /** @param array<int, string> $tokens */
    public static function containsAll(array $tokens, string $haystack): bool
    {
        if ($tokens === []) {
            return false;
        }

        $hay = self::normalize($haystack);
        foreach ($tokens as $token) {
            if (! str_contains($hay, $token)) {
                return false;
            }
        }

        return true;
    }
}

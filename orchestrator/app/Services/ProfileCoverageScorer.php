<?php

namespace App\Services;

/**
 * Deterministic per-field completeness scorer for a company profile. Drives the
 * universal collector's gather loop: it reports how filled each target field is
 * (0..1), the overall coverage, and which fields are still "gaps" to target with
 * another source. Kept rule-based (no LLM) so the loop is cheap and predictable —
 * the LLM judge (CompanyMatchJudge) handles the separate concern of company match.
 */
class ProfileCoverageScorer
{
    /**
     * The pre-catalogue field set. No longer the definition of "complete" — that now
     * comes from {@see FieldRequirements} — but kept as the default for callers that
     * pass no declared list, and as the fallback for an unseeded catalogue.
     */
    public const FIELDS = ['description', 'activity', 'emails', 'phones', 'address', 'social_links'];

    /**
     * @param array<string, mixed> $profile
     * @param array<int, string>|null $declared Fields this run must fill; null keeps
     *                                          the legacy six for callers that predate
     *                                          the catalogue.
     *
     * @return array{fields: array<string, float>, overall: float, missing: array<int, string>}
     */
    public function score(array $profile, ?array $declared = null): array
    {
        $fields = $declared !== null && $declared !== [] ? array_values($declared) : self::FIELDS;

        $scores = [];
        foreach ($fields as $field) {
            $scores[$field] = $this->fieldScore($field, $profile[$field] ?? null);
        }

        $overall = round(array_sum($scores) / max(1, count($scores)), 3);

        $threshold = (float) config('react.coverage.gap_threshold', 0.5);
        $missing = array_keys(array_filter($scores, static fn (float $s): bool => $s < $threshold));

        return [
            'fields' => $scores,
            'overall' => $overall,
            'missing' => array_values($missing),
        ];
    }

    private function fieldScore(string $field, mixed $value): float
    {
        return match ($field) {
            'description' => $this->prose($value, 120),
            'activity' => $this->prose($value, 40),
            'emails', 'phones', 'social_links' => $this->hasItems($value) ? 1.0 : 0.0,
            // Catalogue fields may be array-typed (brands, locations, …). Only the
            // named arms above carry legacy scoring rules; anything else is scored
            // present/absent, using item count for arrays and non-empty text
            // otherwise.
            default => is_array($value)
                ? ($this->hasItems($value) ? 1.0 : 0.0)
                : (trim((string) ($value ?? '')) !== '' ? 1.0 : 0.0),
        };
    }

    /**
     * Prose fields ramp from 0 (empty) to 1 (>= $full chars), so a one-line stub
     * scores as a partial gap rather than "complete".
     */
    private function prose(mixed $value, int $full): float
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return 0.0;
        }

        return min(1.0, round(mb_strlen($text) / $full, 3));
    }

    private function hasItems(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}

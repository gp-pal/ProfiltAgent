<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Accumulating best-known profile for a target company, built up by the
 * universal collector across runs and sources.
 */
class CompanyProfile extends Model
{
    /** A source whose match verdict was verified established this field's value. */
    public const VERIFICATION_VERIFIED = 'verified';

    /** The value's source was accepted with nothing verifying it (a judge outage, or no verdict). */
    public const VERIFICATION_UNVERIFIED = 'unverified';

    /** We hold no verification record for this value — never to be read as "verified". */
    public const VERIFICATION_UNKNOWN = 'unknown';

    protected $fillable = [
        'company_key',
        'company_code',
        'name',
        'domain',
        'fields',
        'field_coverage',
        'field_sources',
        'field_verification',
        'overall_score',
        'verdict',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'field_coverage' => 'array',
            'field_sources' => 'array',
            'field_verification' => 'array',
            'overall_score' => 'float',
        ];
    }

    /**
     * What is known about one field's match verification. THE place where an absent record
     * is interpreted, so no reader can turn "we never recorded it" into "a judge checked it":
     * every profile row that predates the `field_verification` column, and every field written
     * before it existed, arrives here as {@see self::VERIFICATION_UNKNOWN}.
     *
     * @return array{status: string, source: ?string, verdict: ?string, basis: ?string, at: ?string}
     */
    public function verificationOf(string $field): array
    {
        $entry = ($this->field_verification ?? [])[$field] ?? null;
        $status = is_array($entry) ? ($entry['status'] ?? null) : null;

        if (!in_array($status, [self::VERIFICATION_VERIFIED, self::VERIFICATION_UNVERIFIED], true)) {
            return ['status' => self::VERIFICATION_UNKNOWN, 'source' => null, 'verdict' => null, 'basis' => null, 'at' => null];
        }

        return [
            'status' => (string) $status,
            'source' => is_string($entry['source'] ?? null) ? $entry['source'] : null,
            'verdict' => is_string($entry['verdict'] ?? null) ? $entry['verdict'] : null,
            'basis' => is_string($entry['basis'] ?? null) ? $entry['basis'] : null,
            'at' => is_string($entry['at'] ?? null) ? $entry['at'] : null,
        ];
    }

    /**
     * Map company codes to their accumulated overall_score, for codes that have
     * a profile. Codes without a profile are omitted (caller treats them as
     * "not enriched"). Used by the Gap firms picker to show local status.
     *
     * @param  array<int, string>  $codes
     * @return array<string, float>
     */
    public static function scoresByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return static::query()
            ->whereIn('company_code', $codes)
            ->pluck('overall_score', 'company_code')
            ->map(static fn ($score): float => (float) $score)
            ->all();
    }
}

<?php

namespace App\Services;

use App\Models\ProfileField;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single source of truth for which fields a run must fill.
 *
 * ProfiltAgent predates the consumer that actually knows what data a report needs, so
 * "complete" used to mean a hardcoded six-field constant. Now the admin catalogue
 * provides the default and the caller may override it per request — whatever the
 * caller declares is what the run is measured against.
 *
 * The catalogue's two flags mean different things, and both matter here:
 * `enabled` gates whether a field is collectible at all — a caller may request it,
 * and it appears in `catalogue()` — but `enabled = false` always drops it, even
 * from an explicit request. `required` gates the DEFAULT only: it is what a caller
 * that sends no `fields[]` gets measured against. A field can be `enabled` for
 * callers that ask for it by name while staying invisible to callers that don't —
 * that's exactly the six new (Task 1) fields today, so an existing integration
 * that never sends `fields[]` keeps seeing the pre-catalogue legacy six.
 */
class FieldRequirements
{
    /** The pre-catalogue field set. Kept as the fallback so an unseeded deploy behaves as before. */
    public const LEGACY = ['description', 'activity', 'emails', 'phones', 'address', 'social_links'];

    private const TTL_SECONDS = 300;

    /**
     * Declared fields for this run: the caller's list when given (filtered to the
     * enabled catalogue, unknown/disabled keys dropped with a warning rather than
     * rejected — one bad key must not cost the caller the entire enrichment); else
     * the catalogue's `required` fields, which is what a caller who declares
     * nothing is measured against.
     *
     * Never returns an empty array — a request whose every key was dropped falls
     * back to the same default as a request that declared nothing.
     *
     * @param array<int, string> $requested
     *
     * @return array<int, string>
     */
    public function resolve(array $requested = []): array
    {
        $catalogue = $this->catalogue();

        if ($catalogue === []) {
            return self::LEGACY;
        }

        if ($requested === []) {
            return $this->requiredKeys();
        }

        $known = [];
        $unknown = [];

        foreach ($requested as $key) {
            $key = trim((string) $key);
            if ($key !== '' && isset($catalogue[$key])) {
                $known[] = $key;
            } elseif ($key !== '') {
                $unknown[] = $key;
            }
        }

        if ($unknown !== []) {
            Log::warning('Unknown profile fields requested', ['fields' => $unknown]);
        }

        // Never hand out an empty declared set. Downstream, [] is indistinguishable
        // from "no list given": the scorer and CompanyProfileService substitute the
        // legacy six, while OfficialSiteSource's harvest loop iterates it and keeps
        // nothing — so a request naming only unknown or disabled keys would fetch the
        // site, extract six fields and then silently discard all of them. Falling
        // back to the default keeps the three consumers in agreement.
        return $known === [] ? $this->requiredKeys() : array_values(array_unique($known));
    }

    /**
     * The enabled catalogue keyed by field key, ordered by sort_order.
     *
     * @return array<string, array{key: string, type: string, label: string, extraction_hint: ?string, required: bool}>
     */
    public function catalogue(): array
    {
        return Cache::remember(ProfileField::CACHE_KEY, self::TTL_SECONDS, static function (): array {
            $out = [];

            foreach (ProfileField::query()->where('enabled', true)->orderBy('sort_order')->orderBy('key')->get() as $field) {
                $key = (string) $field->key;

                // The Filament form validates this shape on entry, but a key can also
                // reach the DB by other means (a raw insert, a future import). An
                // unvalidated key flows straight into the LLM JSON schema and the API
                // response; a malformed one there means a 400 from Anthropic that the
                // existing catch turns into a silently empty profile. Drop it here too.
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                    Log::warning('Skipping malformed profile field key', ['key' => $key]);
                    continue;
                }

                $out[$key] = [
                    'key' => $key,
                    'type' => in_array($field->type, ProfileField::TYPES, true) ? (string) $field->type : 'string',
                    'label' => (string) $field->label,
                    'extraction_hint' => $field->extraction_hint,
                    'required' => (bool) $field->required,
                ];
            }

            return $out;
        });
    }

    /**
     * The catalogue's `required` keys — the default declared set for a caller
     * that sends no `fields[]`. Falls back to {@see LEGACY} if the catalogue is
     * unseeded, or seeded but nothing in it is marked `required`.
     *
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        $required = array_filter($this->catalogue(), static fn (array $f): bool => $f['required']);

        return $required === [] ? self::LEGACY : array_keys($required);
    }
}

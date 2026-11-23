<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates the POST /api/run payload. Endpoint auth is enforced separately by the
 * `api.key` middleware, so authorization here is always granted.
 */
class RunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Always answer a validation failure with 422 JSON. This is a JSON API called
     * machine-to-machine; without this, a client that omits `Accept: application/json`
     * would get FormRequest's default 302 redirect (to the app root) instead of the
     * error — and behind the TLS-terminating proxy that redirect points at an
     * unreachable https:// host, so the failure would be invisible to the caller.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'error' => 'validation_failed',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company' => 'required|string|min:1|max:255',
            'domain' => 'nullable|string|max:255',
            // The company's registry code, when the caller already
            // knows it. Supplying it lets CompanyRegistrySource use the indexed
            // factsForCode() lookup instead of resolving by name/domain with
            // leading-wildcard LIKE scans over the whole firms table.
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],
            // Directors / executives supplied by the caller — the caller
            // owns who they are, so we never guess. Present => the news
            // dimension also searches "person + company"; absent => no person search.
            'people' => 'nullable|array|max:10',
            'people.*.name' => ['required', 'string', 'max:120', 'regex:/^[\p{L}\p{M}\s.\'-]+$/u'],
            'people.*.role' => 'nullable|string|max:60',
            // Which profile fields this caller needs. Absent => the admin catalogue
            // decides. The key format mirrors ProfileField::key, so an unknown-but-
            // well-formed key is dropped by FieldRequirements rather than 422'd.
            'fields' => 'nullable|array|max:40',
            'fields.*' => ['string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            // Values the caller already holds. Recorded with provenance `caller` and
            // never searched for again — the point is to stop re-scraping what the
            // consumer can already tell us. Keys follow the field-key format; unknown
            // keys are dropped downstream, exactly as `fields[]` unknowns are.
            'known_fields' => 'nullable|array|max:40',
            'known_fields.*' => ['nullable'],
            // Constrain to UUID/ULID-safe chars: prevents path traversal via the
            // session segment of runs/{session}/... artifact paths.
            'session' => ['nullable', 'string', 'max:36', 'regex:/^[A-Za-z0-9-]+$/'],
            'thinking' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'collect' => 'nullable|boolean',
            // Run the source ladder even when every declared field is already filled, so the
            // sources can establish match verification for values we hold no record for (a
            // profile enriched before verification was stored keeps reading `unknown`
            // otherwise, because the registry alone ends the ladder). Costs a full ladder, so
            // the caller asks for it deliberately.
            'verify_fields' => 'nullable|boolean',
        ];
    }
}

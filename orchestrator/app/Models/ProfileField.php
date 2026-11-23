<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One field in the profile catalogue. `type` is constrained to the values the LLM
 * schema builder accepts; `extraction_hint` is handed to the prompt verbatim.
 */
class ProfileField extends Model
{
    public const CACHE_KEY = 'profile_fields.catalogue';

    /** Types the dynamic LLM schema knows how to express. */
    public const TYPES = ['string', 'text', 'array', 'date'];

    protected $fillable = ['key', 'label', 'type', 'group', 'extraction_hint', 'required', 'enabled', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    /** An admin edit must land on the next run, not after a TTL. */
    protected static function booted(): void
    {
        static::saved(static fn (): bool => Cache::forget(self::CACHE_KEY));
        static::deleted(static fn (): bool => Cache::forget(self::CACHE_KEY));
    }
}

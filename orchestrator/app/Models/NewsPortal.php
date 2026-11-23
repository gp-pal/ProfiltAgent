<?php

namespace App\Models;

use App\Services\NewsPortalRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One whitelisted news portal. Matched by exact host or `.suffix`, so `bns.lt`
 * also covers `sc.bns.lt`.
 */
class NewsPortal extends Model
{
    protected $fillable = ['domain', 'label', 'enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * An admin edit must take effect on the next run, not after the cache TTL —
     * so every write drops the cached domain list.
     */
    protected static function booted(): void
    {
        static::saved(static fn (): bool => Cache::forget(NewsPortalRegistry::CACHE_KEY));
        static::deleted(static fn (): bool => Cache::forget(NewsPortalRegistry::CACHE_KEY));
    }
}

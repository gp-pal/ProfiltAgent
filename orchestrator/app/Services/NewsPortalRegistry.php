<?php

namespace App\Services;

use App\Models\NewsPortal;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the admin-editable news-portal whitelist. Injected (not called
 * statically) so unit tests can stub the list without touching the database.
 * Cached with a short TTL as a backstop; the model invalidates on write.
 */
class NewsPortalRegistry
{
    public const CACHE_KEY = 'news_portals.enabled_domains';

    private const TTL_SECONDS = 300;

    /** @return array<int, string> */
    public function enabledDomains(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, static fn (): array => NewsPortal::query()
            ->where('enabled', true)
            ->orderBy('domain')
            ->pluck('domain')
            ->all());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

<?php

namespace Tests\Unit;

use App\Models\NewsPortal;
use App\Services\NewsPortalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsPortalRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_enabled_domains(): void
    {
        NewsPortal::query()->where('domain', 'delfi.lt')->update(['enabled' => false]);

        $domains = (new NewsPortalRegistry())->enabledDomains();

        $this->assertNotContains('delfi.lt', $domains);
        $this->assertContains('15min.lt', $domains);
    }

    public function test_saving_a_portal_invalidates_the_cache(): void
    {
        $registry = new NewsPortalRegistry();
        $this->assertNotContains('naujas.lt', $registry->enabledDomains());

        NewsPortal::create(['domain' => 'naujas.lt', 'label' => 'Naujas']);

        // Without invalidation this would still serve the cached list.
        $this->assertContains('naujas.lt', $registry->enabledDomains());
    }

    public function test_deleting_a_portal_invalidates_the_cache(): void
    {
        $registry = new NewsPortalRegistry();
        $this->assertContains('kmt.lt', $registry->enabledDomains());

        NewsPortal::query()->where('domain', 'kmt.lt')->first()->delete();

        $this->assertNotContains('kmt.lt', $registry->enabledDomains());
    }
}

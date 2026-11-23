<?php

namespace Tests\Unit;

use App\Models\NewsPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_the_previously_hardcoded_portals(): void
    {
        // The whitelist moved from a PHP const to the DB; the seed must reproduce
        // it exactly so deploying this change cannot alter behaviour.
        $expected = [
            'delfi.lt', '15min.lt', 'lrytas.lt', 'lrt.lt', 'tv3.lt', 've.lt',
            'diena.lt', 'kauno.diena.lt', 'alfa.lt', 'bns.lt', 'respublika.lt',
            'vakaruekspresas.lt', 'madeinvilnius.lt', 'kmt.lt',
        ];

        $this->assertEqualsCanonicalizing(
            $expected,
            NewsPortal::query()->pluck('domain')->all(),
        );
        $this->assertSame(14, NewsPortal::query()->where('enabled', true)->count());
    }

    public function test_enabled_is_cast_to_boolean(): void
    {
        $portal = NewsPortal::create(['domain' => 'pavyzdys.lt', 'label' => 'Pavyzdys', 'enabled' => 1]);

        $this->assertTrue($portal->fresh()->enabled);
    }

    public function test_domain_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        NewsPortal::create(['domain' => 'delfi.lt', 'label' => 'Dublis']);
    }
}

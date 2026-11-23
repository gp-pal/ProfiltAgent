<?php

namespace Tests\Unit;

use App\Services\Memory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    private const CACHE_PATH = 'runs/memory_cache.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_failed_cache_write_never_costs_the_caller_its_domain(): void
    {
        // The domain cache is an optimisation, nothing more, and storeDomain() is called
        // from DomainResolutionService AFTER a domain has been resolved. In production the
        // write threw, the exception landed in that resolver's catch, and the resolved
        // domain was discarded — official_site then had nothing to fetch. A failed write
        // must cost a slower next run and nothing else.
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->andThrow(new \RuntimeException('mkdir(): Permission denied'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $m = new Memory();
        $m->storeDomain('Acmena', 'acmena.lt');

        $this->assertSame('acmena.lt', $m->getDomain('Acmena'));
    }

    public function test_the_cache_lives_on_the_local_disk_not_the_process_working_directory(): void
    {
        // `app.runs_dir` was resolved against the process working directory, which differs
        // between artisan and php-fpm; under php-fpm it pointed at an app root the web user
        // cannot write, so the cache was never persisted once and every run re-searched for
        // a domain it had already found. Artifacts belong on the local disk, under the same
        // `runs/` prefix SessionFs uses.
        (new Memory())->storeDomain('Acmena', 'acmena.lt');

        Storage::disk('local')->assertExists(self::CACHE_PATH);
        $this->assertSame('acmena.lt', (new Memory())->getDomain('Acmena'));
    }

    public function test_store_and_get_domain_roundtrip(): void
    {
        $m = new Memory();
        $m->storeDomain('Acmena', 'acmena.lt');

        $this->assertSame('acmena.lt', (new Memory())->getDomain('Acmena'));
        $this->assertNull((new Memory())->getDomain('Nežinoma'));
    }

    public function test_get_domain_respects_ttl(): void
    {
        (new Memory())->storeDomain('Acmena', 'acmena.lt');

        // Manually age the cache entry beyond 24h
        $data = json_decode((string) Storage::disk('local')->get(self::CACHE_PATH), true);
        $data['domains']['acmena']['ts'] = time() - 90000; // > 86400
        Storage::disk('local')->put(self::CACHE_PATH, json_encode($data));

        $this->assertNull((new Memory())->getDomain('Acmena'));
    }

    public function test_store_domain_never_persists_an_aggregator(): void
    {
        (new Memory())->storeDomain('Rūtos Vainoravičienės IĮ', 'scoris.lt');

        $this->assertNull((new Memory())->getDomain('Rūtos Vainoravičienės IĮ'));
    }

    public function test_get_domain_self_heals_aggregator_poison(): void
    {
        // Simulate a poisoned entry written before the store guard existed.
        (new Memory())->storeDomain('Poison', 'acmena.lt');
        $data = json_decode((string) Storage::disk('local')->get(self::CACHE_PATH), true);
        $data['domains']['poison']['domain'] = 'scoris.lt';
        Storage::disk('local')->put(self::CACHE_PATH, json_encode($data));

        $this->assertNull((new Memory())->getDomain('Poison'), 'stale aggregator must not be returned');
    }
}

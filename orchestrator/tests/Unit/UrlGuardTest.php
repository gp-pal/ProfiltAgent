<?php

namespace Tests\Unit;

use App\Validation\UrlGuard;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    public function test_allows_public_https_url(): void
    {
        $this->expectNotToPerformAssertions();
        (new UrlGuard())->assertSafe('https://93.184.216.34/apie');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlGuard())->assertSafe('file:///etc/passwd');
    }

    public function test_rejects_embedded_credentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlGuard())->assertSafe('https://user:pass@example.com');
    }

    public function test_rejects_loopback_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlGuard())->assertSafe('http://127.0.0.1/');
    }

    public function test_rejects_private_range_ip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlGuard())->assertSafe('http://192.168.1.1/');
    }
}
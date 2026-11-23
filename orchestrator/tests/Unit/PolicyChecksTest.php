<?php

namespace Tests\Unit;

use App\Validation\PolicyChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyChecksTest extends TestCase
{
    /**
     * @return array{actions: array<int, array<string, mixed>>}
     */
    private function planWithGoto(string $url): array
    {
        return ['actions' => [['do' => 'goto', 'url' => $url]]];
    }

    public function test_allows_public_http_url(): void
    {
        // Public literal IP (no DNS) — must pass the SSRF guard.
        $this->assertTrue((new PolicyChecks())->check($this->planWithGoto('https://93.184.216.34/')));
    }

    #[DataProvider('blockedUrls')]
    public function test_blocks_unsafe_url(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PolicyChecks())->check($this->planWithGoto($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 192.168' => ['http://192.168.1.1/'],
            'file scheme' => ['file:///etc/passwd'],
            'javascript scheme' => ['javascript:alert(1)'],
            'embedded credentials' => ['https://user:pass@93.184.216.34/'],
        ];
    }
}

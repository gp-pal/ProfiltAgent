<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fail-soft reachability panel for the core dependencies: the database, the
 * Playwright browser runner, and SearXNG. Every check is wrapped so a timeout
 * or exception renders "down"/"unknown" instead of erroring the dashboard.
 */
class SystemHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    /** Short HTTP timeout so the dashboard never hangs on a dead dependency. */
    private const HTTP_TIMEOUT = 2;

    protected function getStats(): array
    {
        [$dbState, $dbColor] = $this->checkDatabase();
        [$runnerState, $runnerColor] = $this->checkHttp(
            config('services.browser_runner.url'),
            '/health',
        );
        [$searxState, $searxColor] = $this->checkHttp(
            config('services.searxng.url'),
            '/',
        );

        return [
            Stat::make('Database', $dbState)
                ->description('Default SQL connection')
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color($dbColor),

            Stat::make('Browser runner', $runnerState)
                ->description('Playwright runner /health')
                ->descriptionIcon('heroicon-m-computer-desktop')
                ->color($runnerColor),

            Stat::make('SearXNG', $searxState)
                ->description('Search backend')
                ->descriptionIcon('heroicon-m-magnifying-glass')
                ->color($searxColor),
        ];
    }

    /**
     * @return array{0: string, 1: string} [label, color]
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['up', 'success'];
        } catch (Throwable) {
            return ['down', 'danger'];
        }
    }

    /**
     * @return array{0: string, 1: string} [label, color]
     */
    private function checkHttp(?string $baseUrl, string $path): array
    {
        if (empty($baseUrl)) {
            return ['unknown', 'gray'];
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->connectTimeout(self::HTTP_TIMEOUT)
                ->get($url);

            // Any completed response below 500 counts as reachable.
            return $response->status() < 500
                ? ['up', 'success']
                : ['down', 'danger'];
        } catch (Throwable) {
            return ['down', 'danger'];
        }
    }
}

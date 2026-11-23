<?php

namespace App\Filament\Widgets;

use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Top-of-dashboard KPI cards: profiles, runs, average run score, recent volume.
 * All queries are cheap aggregates and degrade gracefully on an empty database.
 */
class CollectionStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalProfiles = CompanyProfile::count();
        $totalRuns = CollectionRun::count();

        // avg() returns null on an empty table; scores are stored as 0..1 floats.
        $avgScore = (float) (CollectionRun::avg('overall_score') ?? 0.0);
        $avgScorePct = (int) round($avgScore * 100);

        $recentRuns = CollectionRun::where('created_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make('Company profiles', (string) $totalProfiles)
                ->description('Accumulated best-known profiles')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),

            Stat::make('Collection runs', (string) $totalRuns)
                ->description('Total pipeline runs recorded')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),

            Stat::make('Average run score', $avgScorePct . '%')
                ->description('Mean overall_score across runs')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($avgScorePct >= 60 ? 'success' : ($avgScorePct > 0 ? 'warning' : 'gray')),

            Stat::make('Runs (last 7 days)', (string) $recentRuns)
                ->description('Runs started in the past week')
                ->descriptionIcon('heroicon-m-clock')
                ->color('success'),
        ];
    }
}

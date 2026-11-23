<?php

namespace App\Filament\Widgets;

use App\Models\CollectionRun;
use Filament\Widgets\ChartWidget;

/**
 * Doughnut chart of collection runs grouped by verdict
 * (e.g. sufficient / partial / none / error, or whatever verdicts exist).
 */
class VerdictDistributionChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Run verdict distribution';

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * Verdict -> hex, so a slice's colour always matches its meaning (green =
     * good, amber = needs work, rose = rejected) regardless of grouping order.
     *
     * @var array<string, string>
     */
    private const VERDICT_COLORS = [
        'sufficient' => '#10b981', // emerald / success
        'pass' => '#10b981',
        'accept' => '#10b981',
        'accepted' => '#10b981',
        'data_limited' => '#06b6d4', // cyan / info — exhausted cleanly, not a problem
        'partial' => '#f59e0b',    // amber / warning
        'review' => '#f59e0b',
        'uncertain' => '#f59e0b',
        'fail' => '#f43f5e',       // rose / danger
        'reject' => '#f43f5e',
        'rejected' => '#f43f5e',
        'none' => '#71717a',       // zinc
        'unknown' => '#71717a',
    ];

    protected function getData(): array
    {
        // Group dynamically so any verdict value is represented; NULL -> "unknown".
        $counts = CollectionRun::query()
            ->selectRaw('verdict, COUNT(*) as aggregate')
            ->groupBy('verdict')
            ->pluck('aggregate', 'verdict');

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($counts as $verdict => $count) {
            $label = $verdict === null || $verdict === '' ? 'unknown' : (string) $verdict;
            $labels[] = $label;
            $data[] = (int) $count;
            $colors[] = self::VERDICT_COLORS[strtolower($label)] ?? '#818cf8'; // indigo fallback
        }

        return [
            'datasets' => [
                [
                    'label' => 'Runs',
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }
}

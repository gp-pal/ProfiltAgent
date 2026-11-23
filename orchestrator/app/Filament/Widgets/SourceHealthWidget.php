<?php

namespace App\Filament\Widgets;

use App\Models\CollectionAction;
use Filament\Widgets\Widget;

/**
 * Per-source success matrix built from CollectionAction rows. Lets an operator
 * compare how each source (registry_db / official_site / open_web / contacts)
 * performs across statuses (ok / rejected / empty / skipped).
 */
class SourceHealthWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    protected string $view = 'filament.widgets.source-health';

    /**
     * Status columns rendered by the view. Any other status is folded into "other".
     *
     * @var array<int, string>
     */
    public const STATUSES = ['ok', 'rejected', 'empty', 'skipped', 'error'];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $grouped = CollectionAction::query()
            ->selectRaw('source, status, COUNT(*) as aggregate')
            ->groupBy('source', 'status')
            ->get();

        $rows = [];

        foreach ($grouped as $row) {
            $source = (string) ($row->source ?? 'unknown');
            $status = (string) ($row->status ?? '');
            $count = (int) $row->aggregate;

            if (! isset($rows[$source])) {
                $rows[$source] = array_fill_keys(self::STATUSES, 0) + ['other' => 0, 'total' => 0];
            }

            if (in_array($status, self::STATUSES, true)) {
                $rows[$source][$status] += $count;
            } else {
                $rows[$source]['other'] += $count;
            }

            $rows[$source]['total'] += $count;
        }

        ksort($rows);

        return [
            'statuses' => self::STATUSES,
            'rows' => $rows,
        ];
    }
}

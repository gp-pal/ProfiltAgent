<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CollectionRuns\CollectionRunResource;
use App\Models\CollectionRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Most recent collection runs so the dashboard shows live agent activity at a
 * glance, each row linking straight to the run's detail.
 */
class LatestRunsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest runs';

    public function table(Table $table): Table
    {
        return $table
            ->query(CollectionRun::query()->latest('started_at')->limit(8))
            ->defaultSort('started_at', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('verdict')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'accept', 'accepted', 'pass', 'sufficient' => 'success',
                        'data_limited' => 'info',
                        'partial', 'review', 'uncertain' => 'warning',
                        'reject', 'rejected', 'fail' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('-'),
                TextColumn::make('overall_score')
                    ->label('Score')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'done' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->since()
                    ->placeholder('-'),
            ])
            ->recordUrl(fn (CollectionRun $record): string => CollectionRunResource::getUrl('view', ['record' => $record]));
    }
}

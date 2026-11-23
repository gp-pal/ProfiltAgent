<?php

namespace App\Filament\Resources\CollectionRuns\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CollectionRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company_code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('verdict')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'accept', 'accepted', 'pass', 'sufficient' => 'success',
                        'data_limited' => 'info',
                        'partial', 'review', 'uncertain' => 'warning',
                        'reject', 'rejected', 'fail' => 'danger',
                        default => 'gray',
                    })
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('overall_score')
                    ->badge()
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 0.7 => 'success',
                        $state >= 0.5 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'done' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('sources_used')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->needsAttention()),
                SelectFilter::make('status')
                    ->options([
                        'running' => 'Running',
                        'done' => 'Done',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('verdict')
                    ->options(fn (): array => \App\Models\CollectionRun::query()
                        ->whereNotNull('verdict')
                        ->distinct()
                        ->orderBy('verdict')
                        ->pluck('verdict', 'verdict')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

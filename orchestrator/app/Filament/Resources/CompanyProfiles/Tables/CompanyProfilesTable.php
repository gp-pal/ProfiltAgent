<?php

namespace App\Filament\Resources\CompanyProfiles\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company_code')
                    ->searchable()
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
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

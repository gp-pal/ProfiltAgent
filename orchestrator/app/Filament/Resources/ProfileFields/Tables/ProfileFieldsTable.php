<?php

namespace App\Filament\Resources\ProfileFields\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ProfileFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Key')->searchable()->sortable(),
                TextColumn::make('label')->label('Name')->searchable(),
                TextColumn::make('group')->label('Group')->badge()->toggleable(),
                TextColumn::make('type')->label('Type'),
                ToggleColumn::make('required')->label('Required'),
                ToggleColumn::make('enabled')->label('Enabled'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\CollectionRuns;

use App\Filament\Resources\CollectionRuns\Pages\ListCollectionRuns;
use App\Filament\Resources\CollectionRuns\Pages\ViewCollectionRun;
use App\Filament\Resources\CollectionRuns\Schemas\CollectionRunInfolist;
use App\Filament\Resources\CollectionRuns\Tables\CollectionRunsTable;
use App\Models\CollectionRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only monitoring resource for collection runs. Records are produced by the
 * pipeline; the admin panel only inspects them, so create/edit/delete are disabled.
 */
class CollectionRunResource extends Resource
{
    protected static ?string $model = CollectionRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = CollectionRun::query()->needsAttention()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Runs needing attention';
    }

    public static function infolist(Schema $schema): Schema
    {
        return CollectionRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CollectionRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollectionRuns::route('/'),
            'view' => ViewCollectionRun::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}

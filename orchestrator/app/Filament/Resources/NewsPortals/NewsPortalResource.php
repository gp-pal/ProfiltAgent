<?php

namespace App\Filament\Resources\NewsPortals;

use App\Filament\Resources\NewsPortals\Pages\CreateNewsPortal;
use App\Filament\Resources\NewsPortals\Pages\EditNewsPortal;
use App\Filament\Resources\NewsPortals\Pages\ListNewsPortals;
use App\Filament\Resources\NewsPortals\Schemas\NewsPortalForm;
use App\Filament\Resources\NewsPortals\Tables\NewsPortalsTable;
use App\Models\NewsPortal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin-editable news-portal whitelist driving the profile's news dimension.
 *
 * Unlike the panel's other resources (read-only views over pipeline-owned
 * records), this one IS the system of record: editing it changes what the
 * collector searches for, with no deploy. Writes invalidate the cached domain
 * list through the model's booted() hook, so a change lands on the next run.
 */
class NewsPortalResource extends Resource
{
    protected static ?string $model = NewsPortal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $recordTitleAttribute = 'domain';

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'News portals';

    public static function form(Schema $schema): Schema
    {
        return NewsPortalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NewsPortalsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsPortals::route('/'),
            'create' => CreateNewsPortal::route('/create'),
            'edit' => EditNewsPortal::route('/{record}/edit'),
        ];
    }
}

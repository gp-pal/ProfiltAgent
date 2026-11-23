<?php

namespace App\Filament\Resources\ProfileFields;

use App\Filament\Resources\ProfileFields\Pages\CreateProfileField;
use App\Filament\Resources\ProfileFields\Pages\EditProfileField;
use App\Filament\Resources\ProfileFields\Pages\ListProfileFields;
use App\Filament\Resources\ProfileFields\Schemas\ProfileFieldForm;
use App\Filament\Resources\ProfileFields\Tables\ProfileFieldsTable;
use App\Models\ProfileField;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The catalogue that defines what a complete company profile contains. Editing it
 * changes what every run collects and how coverage is scored — no deploy involved.
 * Retire a field that is not earning its cost by switching `enabled` off.
 */
class ProfileFieldResource extends Resource
{
    protected static ?string $model = ProfileField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $recordTitleAttribute = 'key';

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'Profile fields';

    public static function form(Schema $schema): Schema
    {
        return ProfileFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfileFieldsTable::configure($table);
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
            'index' => ListProfileFields::route('/'),
            'create' => CreateProfileField::route('/create'),
            'edit' => EditProfileField::route('/{record}/edit'),
        ];
    }
}

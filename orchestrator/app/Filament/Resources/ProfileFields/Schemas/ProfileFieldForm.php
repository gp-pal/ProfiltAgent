<?php

namespace App\Filament\Resources\ProfileFields\Schemas;

use App\Models\ProfileField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class ProfileFieldForm
{
    /**
     * Keys that already mean something else in the API response or the collector's
     * internal fields — `name`/`domain` are target identity, `news`/`people` are
     * separate dimensions merged outside the coverage fields, and `quality` is the
     * official-site match judge's own verdict. Reusing one here would collide: e.g.
     * a `quality` field would harvest the judge's array into `fields['quality']` and
     * crash the admin's scalar renderer.
     *
     * @var array<int, string>
     */
    private const RESERVED_KEYS = ['name', 'domain', 'news', 'people', 'quality'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Key')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z][a-z0-9_]*$/')
                    ->rule(Rule::notIn(self::RESERVED_KEYS))
                    ->helperText(
                        'Used in the API response and the LLM schema: lowercase letters, digits, underscores. '
                        . 'Rezervuoti raktai (negalimi): ' . implode(', ', self::RESERVED_KEYS) . '.',
                    ),
                TextInput::make('label')->label('Name')->required()->maxLength(255),
                Select::make('type')
                    ->label('Type')
                    ->options(array_combine(ProfileField::TYPES, ProfileField::TYPES))
                    ->default('string')
                    ->required()
                    ->helperText('A closed list — the LLM schema cannot express other types.'),
                TextInput::make('group')->label('Group')->maxLength(64),
                Textarea::make('extraction_hint')
                    ->label('Extraction hint')
                    ->rows(3)
                    ->helperText('Passed to the LLM verbatim — explain what the field means and what NOT to include.'),
                Toggle::make('required')->label('Required')->default(false),
                Toggle::make('enabled')->label('Enabled')->default(true),
                TextInput::make('sort_order')->label('Sort order')->numeric()->default(0),
            ]);
    }
}

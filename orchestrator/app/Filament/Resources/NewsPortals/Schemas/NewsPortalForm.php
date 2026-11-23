<?php

namespace App\Filament\Resources\NewsPortals\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class NewsPortalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('domain')
                    ->label('Domain')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Registered domain without “www”, e.g. delfi.lt. Subdomains (sc.bns.lt) match automatically.'),
                TextInput::make('label')
                    ->label('Name')
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->label('Enabled')
                    ->default(true)
                    ->helperText('A disabled portal is not searched and its mentions are not counted.'),
            ]);
    }
}

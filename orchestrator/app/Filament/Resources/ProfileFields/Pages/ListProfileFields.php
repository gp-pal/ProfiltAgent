<?php

namespace App\Filament\Resources\ProfileFields\Pages;

use App\Filament\Resources\ProfileFields\ProfileFieldResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfileFields extends ListRecords
{
    protected static string $resource = ProfileFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

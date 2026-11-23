<?php

namespace App\Filament\Resources\NewsPortals\Pages;

use App\Filament\Resources\NewsPortals\NewsPortalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsPortals extends ListRecords
{
    protected static string $resource = NewsPortalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

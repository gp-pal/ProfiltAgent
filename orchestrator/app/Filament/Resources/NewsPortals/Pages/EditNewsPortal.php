<?php

namespace App\Filament\Resources\NewsPortals\Pages;

use App\Filament\Resources\NewsPortals\NewsPortalResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsPortal extends EditRecord
{
    protected static string $resource = NewsPortalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

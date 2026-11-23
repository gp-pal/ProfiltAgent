<?php

namespace App\Filament\Resources\ProfileFields\Pages;

use App\Filament\Resources\ProfileFields\ProfileFieldResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProfileField extends EditRecord
{
    protected static string $resource = ProfileFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

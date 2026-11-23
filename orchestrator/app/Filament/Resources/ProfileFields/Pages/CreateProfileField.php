<?php

namespace App\Filament\Resources\ProfileFields\Pages;

use App\Filament\Resources\ProfileFields\ProfileFieldResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProfileField extends CreateRecord
{
    protected static string $resource = ProfileFieldResource::class;
}

<?php

namespace App\Filament\Resources\CollectionRuns\Pages;

use App\Filament\Resources\CollectionRuns\CollectionRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCollectionRun extends ViewRecord
{
    protected static string $resource = CollectionRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

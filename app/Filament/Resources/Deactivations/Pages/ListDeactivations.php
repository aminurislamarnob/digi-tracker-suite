<?php

namespace App\Filament\Resources\Deactivations\Pages;

use App\Filament\Resources\Deactivations\DeactivationResource;
use Filament\Resources\Pages\ListRecords;

class ListDeactivations extends ListRecords
{
    protected static string $resource = DeactivationResource::class;
}

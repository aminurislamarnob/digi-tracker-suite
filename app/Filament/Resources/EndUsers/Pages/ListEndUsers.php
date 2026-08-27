<?php

namespace App\Filament\Resources\EndUsers\Pages;

use App\Filament\Resources\EndUsers\EndUserResource;
use Filament\Resources\Pages\ListRecords;

class ListEndUsers extends ListRecords
{
    protected static string $resource = EndUserResource::class;
}

<?php

namespace App\Filament\Resources\PrintJobs\Pages;

use App\Filament\Resources\PrintJobs\PrintJobResource;
use Filament\Resources\Pages\ListRecords;

class ListPrintJobs extends ListRecords
{
    protected static string $resource = PrintJobResource::class;
}

<?php

namespace App\Filament\Resources\PrintJobs;

use App\Filament\Resources\PrintJobs\Pages\ListPrintJobs;
use App\Filament\Resources\PrintJobs\Tables\PrintJobsTable;
use App\Models\PrintJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PrintJobResource extends Resource
{
    protected static ?string $model = PrintJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Evento';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'stampa';

    protected static ?string $pluralModelLabel = 'stampe';

    public static function table(Table $table): Table
    {
        return PrintJobsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintJobs::route('/'),
        ];
    }
}

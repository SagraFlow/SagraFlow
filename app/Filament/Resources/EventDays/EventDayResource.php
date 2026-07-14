<?php

namespace App\Filament\Resources\EventDays;

use App\Filament\Resources\EventDays\Pages\ManageEventDays;
use App\Filament\Resources\EventDays\Schemas\EventDayForm;
use App\Filament\Resources\EventDays\Tables\EventDaysTable;
use App\Models\EventDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class EventDayResource extends Resource
{
    protected static ?string $model = EventDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Evento';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $modelLabel = 'giornata';

    protected static ?string $pluralModelLabel = 'giornate';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->display_name;
    }

    public static function form(Schema $schema): Schema
    {
        return EventDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventDaysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEventDays::route('/'),
        ];
    }
}

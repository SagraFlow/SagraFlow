<?php

namespace App\Filament\Resources\EventDays\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventDayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                DatePicker::make('date')
                    ->label('Data')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->unique(ignoreRecord: true),
                TextInput::make('label')
                    ->label('Etichetta')
                    ->placeholder('Es. Venerdì, Sabato, …')
                    ->maxLength(100),
            ]);
    }
}

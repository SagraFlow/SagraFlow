<?php

namespace App\Filament\Resources\CashRegisters\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CashRegisterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Toggle::make('active')
                    ->label('Attiva')
                    ->default(true),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Select::make('printer_id')
                    ->label('Stampante locale')
                    ->relationship('printer', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Nessuna')
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'Questa stampante è già la locale di un\'altra cassa.',
                    ]),
            ]);
    }
}

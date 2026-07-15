<?php

namespace App\Filament\Resources\Printers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PrinterForm
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
                TextInput::make('ip_address')
                    ->label('Indirizzo IP')
                    ->required()
                    ->ipv4()
                    ->maxLength(45),
                TextInput::make('port')
                    ->label('Porta')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(9100)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('ip_address', $get('ip_address')),
                    )
                    ->validationMessages([
                        'unique' => 'Esiste già una stampante con questo indirizzo IP e porta.',
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\CardTerminals\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class CardTerminalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Toggle::make('active')
                    ->label('Attivo')
                    ->default(true),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                TextInput::make('ip_address')
                    ->label('Indirizzo IP')
                    ->helperText('Riservalo sul router: la cassa raggiunge il terminale per indirizzo.')
                    ->required()
                    ->ipv4()
                    ->maxLength(45),
                TextInput::make('port')
                    ->label('Porta')
                    ->helperText('Quella configurata sul terminale, sopra 1024.')
                    ->required()
                    ->integer()
                    ->minValue(1024)
                    ->maxValue(65535)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('ip_address', $get('ip_address')),
                    )
                    ->validationMessages([
                        'unique' => 'Esiste già un terminale con questo indirizzo IP e porta.',
                    ]),
                TextInput::make('terminal_id')
                    ->label('Terminal ID')
                    ->helperText('Le 8 cifre assegnate da Nexi, zeri iniziali compresi.')
                    ->required()
                    ->regex('/^\d{8}$/')
                    ->maxLength(8)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'regex' => 'Il terminal ID è di 8 cifre.',
                        'unique' => 'Questo terminal ID è già di un altro terminale.',
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\Filament\Forms\Components\MoneyInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IngredientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Toggle::make('available')
                    ->label('Disponibile')
                    ->default(true),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100),
                MoneyInput::make('surcharge')
                    ->label('Supplemento')
                    ->helperText('Costo aggiuntivo quando usato come extra.')
                    ->default(0)
                    ->required(),
                TextInput::make('stock')
                    ->label('Giacenza')
                    ->helperText('Lascia vuoto per non tracciare il magazzino di questo ingrediente.')
                    ->numeric()
                    ->minValue(0),
            ]);
    }
}

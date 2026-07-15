<?php

namespace App\Filament\Resources\Food\Schemas;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\EventDay;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FoodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('active')
                    ->label('Attiva')
                    ->default(true)
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100),
                Select::make('category_id')
                    ->label('Categoria')
                    ->relationship('category', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                MoneyInput::make('price')
                    ->label('Prezzo')
                    ->required(),
                Select::make('eventDays')
                    ->label('Giornate')
                    ->helperText('Lascia vuoto per rendere la pietanza disponibile tutte le giornate.')
                    ->relationship('eventDays', 'date')
                    ->getOptionLabelFromRecordUsing(fn (EventDay $record): string => $record->display_name)
                    ->multiple()
                    ->preload(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
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
                    ->maxLength(100),
            ]);
    }
}

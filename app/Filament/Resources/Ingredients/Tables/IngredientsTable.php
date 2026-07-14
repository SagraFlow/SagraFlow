<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Filament\Tables\Columns\MoneyColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('surcharge')
                    ->label('Supplemento')
                    ->sortable(),
                TextColumn::make('stock')
                    ->label('Giacenza')
                    ->numeric()
                    ->sortable()
                    ->placeholder('Non tracciato'),
                IconColumn::make('available')
                    ->label('Disponibile')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

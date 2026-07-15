<?php

namespace App\Filament\Resources\Food\Tables;

use App\Filament\Tables\Columns\MoneyColumn;
use App\Models\Food;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FoodTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('eventDays'))
            ->columns([
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('price')
                    ->label('Prezzo')
                    ->sortable(),
                TextColumn::make('eventDays')
                    ->label('Giornate')
                    ->badge()
                    ->placeholder('Tutte')
                    ->state(fn (Food $record): ?array => $record->eventDays->isEmpty()
                        ? null
                        : $record->eventDays->pluck('display_name')->all()),
                IconColumn::make('active')
                    ->label('Attiva')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

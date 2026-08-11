<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Filament\Tables\Columns\MoneyColumn;
use App\Models\Ingredient;
use App\Models\StockReservation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
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
                IconColumn::make('active')
                    ->label('Attivo')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium)
                    // Editing the stock while a checkout holds units of this
                    // ingredient would desync the hold: block it until the
                    // payments in progress are done.
                    ->before(function (array $data, Ingredient $record, EditAction $action): void {
                        $newStock = ($data['stock'] === null || $data['stock'] === '') ? null : (int) $data['stock'];

                        if ($newStock !== $record->stock && StockReservation::holdsIngredient($record->id)) {
                            Notification::make()
                                ->danger()
                                ->title('Giacenza bloccata')
                                ->body("Ci sono pagamenti in corso che riservano «{$record->name}». Modifica la giacenza quando sono conclusi.")
                                ->send();

                            $action->halt();
                        }
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

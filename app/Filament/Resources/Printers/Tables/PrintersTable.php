<?php

namespace App\Filament\Resources\Printers\Tables;

use App\Models\Printer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrintersTable
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
                TextColumn::make('ip_address')
                    ->label('Indirizzo IP')
                    ->searchable(),
                TextColumn::make('port')
                    ->label('Porta'),
                TextColumn::make('cashRegister.name')
                    ->label('Cassa')
                    ->placeholder('—'),
                IconColumn::make('active')
                    ->label('Attiva')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium),
                DeleteAction::make()
                    ->before(function (Printer $record, DeleteAction $action): void {
                        if ($record->cashRegister()->exists()) {
                            Notification::make()
                                ->title('Impossibile eliminare la stampante')
                                ->body('È collegata alla cassa "'.$record->cashRegister->name.'". Scollegala prima di eliminarla.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

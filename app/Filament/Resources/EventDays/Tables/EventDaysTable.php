<?php

namespace App\Filament\Resources\EventDays\Tables;

use App\Exceptions\EventDayException;
use App\Models\EventDay;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Etichetta')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->state(fn (EventDay $record): string => match (true) {
                        $record->closed_at !== null => 'Chiusa',
                        $record->opened_at !== null => 'Aperta',
                        default => 'Pianificata',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aperta' => 'success',
                        'Chiusa' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('opened_at')
                    ->label('Apertura')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('closed_at')
                    ->label('Chiusura')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('foods_count')
                    ->label('Pietanze esclusive')
                    ->counts('foods')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Apri')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (EventDay $record): bool => $record->opened_at === null && $record->closed_at === null && EventDay::current() === null)
                    ->action(function (EventDay $record): void {
                        try {
                            $record->open(auth()->user());
                            Notification::make()->title('Giornata aperta')->success()->send();
                        } catch (EventDayException $e) {
                            Notification::make()->title('Impossibile aprire la giornata')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('close')
                    ->label('Chiudi')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (EventDay $record): bool => $record->isOpen())
                    ->action(function (EventDay $record): void {
                        try {
                            $record->close(auth()->user());
                            Notification::make()->title('Giornata chiusa')->success()->send();
                        } catch (EventDayException $e) {
                            Notification::make()->title('Impossibile chiudere la giornata')->body($e->getMessage())->danger()->send();
                        }
                    }),
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

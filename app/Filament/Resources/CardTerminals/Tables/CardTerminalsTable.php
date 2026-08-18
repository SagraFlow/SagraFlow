<?php

namespace App\Filament\Resources\CardTerminals\Tables;

use App\CardPayments\TerminalProbe;
use App\Models\CardTerminal;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardTerminalsTable
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
                TextColumn::make('terminal_id')
                    ->label('Terminal ID')
                    ->searchable(),
                TextColumn::make('cashRegisters.name')
                    ->label('Casse')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('claimed_at')
                    ->label('In uso')
                    ->badge()
                    ->color('warning')
                    ->placeholder('-')
                    ->state(fn (CardTerminal $record): ?string => $record->busyRegisterName()),
                IconColumn::make('active')
                    ->label('Attivo')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                // Asked here and nowhere on a timer: the terminal holds one
                // conversation at a time, so the question is put when somebody
                // wants the answer - before service, or when a till says the
                // terminal is not there.
                Action::make('probe')
                    ->label('Prova')
                    ->icon(Heroicon::OutlinedSignal)
                    ->modalHeading(fn (CardTerminal $record): string => "Stato di «{$record->name}»")
                    ->modalContent(function (CardTerminal $record) {
                        $result = app(TerminalProbe::class)->probe($record);

                        return view('filament.card-terminals.probe-result', [
                            'status' => $result['status'],
                            'error' => $result['error'],
                            'busyWith' => $result['busyWith'],
                            'clock' => self::readableClock($result['status']?->dateTime),
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->modalWidth(Width::Medium),
                EditAction::make()
                    ->modalWidth(Width::Medium),
                DeleteAction::make()
                    ->before(function (CardTerminal $record, DeleteAction $action): void {
                        if ($record->cashRegisters()->exists()) {
                            Notification::make()
                                ->title('Impossibile eliminare il terminale')
                                ->body('È collegato a: '.$record->cashRegisters->pluck('name')->implode(', ').'. Scollegalo prima di eliminarlo.')
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

    /**
     * The terminal reports its clock as DDMMYYhhmm. Shown as it would be read
     * aloud, because the one thing worth noticing here is a terminal whose
     * clock has drifted: its time is what ends up on the transaction.
     */
    protected static function readableClock(?string $raw): string
    {
        if ($raw === null || strlen($raw) !== 10) {
            return '-';
        }

        return sprintf(
            '%s/%s/%s %s:%s',
            substr($raw, 0, 2),
            substr($raw, 2, 2),
            substr($raw, 4, 2),
            substr($raw, 6, 2),
            substr($raw, 8, 2),
        );
    }
}

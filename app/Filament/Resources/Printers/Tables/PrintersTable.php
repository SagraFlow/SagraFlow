<?php

namespace App\Filament\Resources\Printers\Tables;

use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Exceptions\PrinterException;
use App\Jobs\SendToPrinterJob;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Printing\PrinterConnection;
use App\Printing\PrinterQueue;
use App\Settings\EventSettings;
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

class PrintersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->poll('10s')
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
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('held_count')
                    ->label('In coda')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Printer $record): int => PrintJob::query()
                        ->where('printer_id', $record->id)
                        ->where('status', PrintJobStatus::Held)
                        ->count())
                    ->visible(fn ($state): bool => (int) $state > 0),
                TextColumn::make('status_changed_at')
                    ->label('Da')
                    ->since()
                    ->placeholder('-')
                    ->tooltip(fn (Printer $record): ?string => $record->last_checked_at !== null
                        ? 'Ultimo controllo: '.$record->last_checked_at->diffForHumans()
                        : null),
                TextColumn::make('cashRegister.name')
                    ->label('Cassa')
                    ->placeholder('-'),
                IconColumn::make('active')
                    ->label('Attiva')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('testPrint')
                    ->label('Test')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->action(function (Printer $record): void {
                        $printJob = PrintJob::create([
                            'printer_id' => $record->id,
                            'printer_name' => $record->name,
                            'type' => PrintJobType::Test,
                            'label' => 'Test di stampa',
                            'status' => PrintJobStatus::Pending,
                            'spec' => [
                                'eventName' => app(EventSettings::class)->eventName,
                                'printerName' => $record->name,
                            ],
                            'queued_at' => now(),
                        ]);

                        SendToPrinterJob::dispatchFor($printJob);

                        Notification::make()->success()->title('Test di stampa in coda')->send();
                    }),
                Action::make('release')
                    ->label('Rilascia')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Printer $record): bool => PrintJob::query()
                        ->where('printer_id', $record->id)
                        ->where('status', PrintJobStatus::Held)
                        ->exists())
                    ->action(function (Printer $record): void {
                        $released = app(PrinterQueue::class)->release($record);

                        Notification::make()
                            ->success()
                            ->title($released > 0 ? "Rimessi in coda {$released} lavori" : 'La stampante non è ancora pronta')
                            ->send();
                    }),
                Action::make('verifyMonitoring')
                    ->label('Verifica')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->action(function (Printer $record): void {
                        $enabled = app(PrinterConnection::class)->offlineStatusEnabled($record->ip_address, $record->port);

                        match ($enabled) {
                            true => Notification::make()->success()->title('Monitoraggio attivo')->body('La stampante riporta lo stato anche quando è offline.')->send(),
                            false => Notification::make()->warning()->title('Da configurare')->body('La stampante non riporta lo stato quando è offline (coperchio/carta).')->send(),
                            default => Notification::make()->warning()->title('Non determinabile')->body('Stampante non raggiungibile o risposta inattesa.')->send(),
                        };
                    }),
                Action::make('configureMonitoring')
                    ->label('Configura')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->requiresConfirmation()
                    ->modalHeading('Configura per il monitoraggio')
                    ->modalDescription('Abilita la segnalazione di stato quando la stampante è offline (coperchio/carta). La stampante si riavvierà per qualche secondo per applicare l\'impostazione.')
                    ->action(function (Printer $record): void {
                        $connection = app(PrinterConnection::class);
                        $enabled = $connection->offlineStatusEnabled($record->ip_address, $record->port);

                        if ($enabled === null) {
                            Notification::make()->warning()->title('Stampante non raggiungibile')->send();

                            return;
                        }

                        if ($enabled) {
                            Notification::make()->success()->title('Già configurata')->send();

                            return;
                        }

                        try {
                            $connection->enableOfflineStatus($record->ip_address, $record->port);
                            Notification::make()->success()->title('Configurata')->body('La stampante si sta riavviando per applicare l\'impostazione.')->send();
                        } catch (PrinterException $exception) {
                            Notification::make()->danger()->title('Configurazione fallita')->body($exception->getMessage())->send();
                        }
                    }),
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

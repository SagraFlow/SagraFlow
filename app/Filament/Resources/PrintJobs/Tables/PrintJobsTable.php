<?php

namespace App\Filament\Resources\PrintJobs\Tables;

use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Jobs\SendToPrinterJob;
use App\Models\PrintJob;
use App\Printing\OrderPrinter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PrintJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data/Ora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('order.number')
                    ->label('Ordine')
                    ->prefix('#')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Documento')
                    ->badge(),
                TextColumn::make('label')
                    ->label('Destinazione'),
                TextColumn::make('printer_name')
                    ->label('Stampante')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('attempts')
                    ->label('Tentativi'),
                TextColumn::make('printed_at')
                    ->label('Stampato il')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                TextColumn::make('error')
                    ->label('Errore')
                    ->placeholder('-')
                    ->limit(40)
                    ->tooltip(fn (PrintJob $record): ?string => $record->error),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(PrintJobStatus::class),
                SelectFilter::make('type')
                    ->label('Documento')
                    ->options(PrintJobType::class),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Annulla')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Annulla stampa')
                    ->modalDescription('La stampa verrà annullata e non verrà inviata.')
                    ->visible(fn (PrintJob $record): bool => in_array($record->status, [PrintJobStatus::Pending, PrintJobStatus::Held], true))
                    ->action(function (PrintJob $record): void {
                        // Same atomic guard as the worker's claim: only cancel a job
                        // that has not been picked up or completed. A queued job then
                        // finds the status un-claimable and no-ops.
                        $cancelled = PrintJob::whereKey($record->id)
                            ->whereIn('status', [PrintJobStatus::Pending, PrintJobStatus::Held])
                            ->update(['status' => PrintJobStatus::Cancelled]);

                        Notification::make()
                            ->title($cancelled ? 'Stampa annullata' : 'La stampa era già in corso o completata')
                            ->{$cancelled ? 'success' : 'warning'}()
                            ->send();
                    }),
                Action::make('reprint')
                    ->label('Ristampa')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (PrintJob $record): bool => $record->printer_id !== null
                        && $record->status !== PrintJobStatus::Sending)
                    ->action(function (PrintJob $record): void {
                        // Reset so the job's atomic claim can pick it up again.
                        $record->update(['status' => PrintJobStatus::Pending, 'error' => null]);
                        SendToPrinterJob::dispatchFor($record);

                        Notification::make()
                            ->success()
                            ->title('Stampa rimessa in coda')
                            ->send();
                    }),
                Action::make('reprintOrder')
                    ->label('Ristampa ordine')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->requiresConfirmation()
                    ->modalHeading('Ristampa ordine')
                    ->modalDescription(fn (PrintJob $record): string => "Rimettere in coda tutte le stampe dell'ordine #{$record->order?->number}?")
                    ->visible(fn (PrintJob $record): bool => $record->order !== null)
                    ->action(function (PrintJob $record): void {
                        app(OrderPrinter::class)->print($record->order);

                        Notification::make()
                            ->success()
                            ->title('Stampa rimessa in coda')
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

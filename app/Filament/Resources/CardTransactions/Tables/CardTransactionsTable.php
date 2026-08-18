<?php

namespace App\Filament\Resources\CardTransactions\Tables;

use App\Enums\CardTransactionStatus;
use App\Models\CardTransaction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CardTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data/Ora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('amount_cents')
                    ->label('Importo')
                    ->money('EUR', divideBy: 100)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('order.number')
                    ->label('Ordine')
                    ->prefix('#')
                    // The gap that matters: money taken with nothing sold.
                    ->placeholder('nessuno')
                    ->color(fn (CardTransaction $record): ?string => $record->isApproved() && $record->order_id === null ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('cashRegister.name')
                    ->label('Cassa')
                    ->placeholder('-'),
                TextColumn::make('terminal.name')
                    ->label('Terminale')
                    // The name people use when talking about it. The eight
                    // digits Nexi knows it by are one hover away, for the day
                    // these rows have to be matched against their report - and
                    // they stand in for the name when a terminal is gone.
                    ->state(fn (CardTransaction $record): string => $record->terminal?->name ?? $record->terminal_id)
                    ->tooltip(fn (CardTransaction $record): string => "Terminal ID {$record->terminal_id}")
                    ->searchable(),
                TextColumn::make('pan_last4')
                    ->label('Carta')
                    ->formatStateUsing(fn (CardTransaction $record): string => trim(($record->cardTypeLabel() ?? '').' ****'.$record->pan_last4))
                    ->placeholder('-'),
                TextColumn::make('authorization_code')
                    ->label('Autorizzazione')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('stan')
                    ->label('STAN')
                    ->placeholder('-')
                    ->searchable(),
                IconColumn::make('manual')
                    ->label('A mano')
                    // A row closed by a person never happened on the terminal:
                    // this is the column that explains a nightly difference.
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedHandRaised)
                    ->falseIcon('')
                    ->trueColor('warning'),
                TextColumn::make('error')
                    ->label('Nota')
                    ->placeholder('-')
                    ->limit(30)
                    ->tooltip(fn (CardTransaction $record): ?string => $record->reason()),
            ])
            ->filters([
                Filter::make('needsAttention')
                    ->label('Da verificare')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->whereIn('status', [CardTransactionStatus::Pending, CardTransactionStatus::Unknown])
                            ->orWhere(fn (Builder $query) => $query
                                ->where('status', CardTransactionStatus::Approved)
                                ->whereNull('order_id'))
                    )),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(CardTransactionStatus::class),
            ])
            // No action here that decides anything about money: what a row
            // needs is a person comparing it with the terminal's own report.
            ->recordActions([])
            ->toolbarActions([]);
    }
}

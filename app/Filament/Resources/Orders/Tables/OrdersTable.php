<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Filament\Tables\Columns\MoneyColumn;
use App\Models\Order;
use App\Printing\OrderPrinter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('N.')
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Data/Ora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('eventDay.displayName')
                    ->label('Giornata'),
                TextColumn::make('service_type')
                    ->label('Servizio')
                    ->badge(),
                TextColumn::make('table_number')
                    ->label('Tavolo')
                    ->placeholder('-'),
                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('cashRegister.name')
                    ->label('Cassa')
                    ->placeholder('-'),
                TextColumn::make('operator.name')
                    ->label('Operatore')
                    ->placeholder('-'),
                TextColumn::make('payment_method')
                    ->label('Pagamento')
                    ->badge(),
                MoneyColumn::make('total')
                    ->label('Totale')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event_day_id')
                    ->label('Giornata')
                    ->relationship('eventDay', 'date'),
                SelectFilter::make('service_type')
                    ->label('Servizio')
                    ->options(ServiceType::class),
                SelectFilter::make('payment_method')
                    ->label('Pagamento')
                    ->options(PaymentMethod::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn (Order $record): string => "Ordine #{$record->number}")
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->modalContent(fn (Order $record): View => view('filament.orders.detail', [
                        'order' => $record->loadMissing([
                            'lines.ingredients',
                            'lines.food.category',
                            'eventDay',
                            'cashRegister',
                            'operator',
                        ]),
                    ])),
                Action::make('reprint')
                    ->label('Ristampa')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->requiresConfirmation()
                    ->modalHeading('Ristampa ordine')
                    ->modalDescription(fn (Order $record): string => "Rimettere in coda scontrino e comande dell'ordine #{$record->number}?")
                    ->action(function (Order $record): void {
                        app(OrderPrinter::class)->print($record);

                        Notification::make()
                            ->success()
                            ->title('Stampa rimessa in coda')
                            ->send();
                    }),
            ]);
    }
}

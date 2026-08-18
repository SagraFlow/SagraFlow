<?php

namespace App\Filament\Resources\CardTransactions;

use App\Enums\CardTransactionStatus;
use App\Filament\Resources\CardTransactions\Pages\ListCardTransactions;
use App\Filament\Resources\CardTransactions\Tables\CardTransactionsTable;
use App\Models\CardTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CardTransactionResource extends Resource
{
    protected static ?string $model = CardTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Evento';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Pagamenti con Carta';

    protected static ?string $modelLabel = 'pagamento con carta';

    protected static ?string $pluralModelLabel = 'pagamenti con carta';

    public static function table(Table $table): Table
    {
        return CardTransactionsTable::configure($table);
    }

    /**
     * How many need a person: an answer that never came, or money taken with no
     * order behind it. Shown on the navigation so it is noticed during the
     * evening rather than counted at the end of it.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = CardTransaction::query()
            ->whereIn('status', [CardTransactionStatus::Pending, CardTransactionStatus::Unknown])
            ->orWhere(fn ($query) => $query
                ->where('status', CardTransactionStatus::Approved)
                ->whereNull('order_id'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardTransactions::route('/'),
        ];
    }
}

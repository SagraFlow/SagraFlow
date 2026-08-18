<?php

namespace App\Filament\Resources\CardTransactions\Pages;

use App\Filament\Resources\CardTransactions\CardTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListCardTransactions extends ListRecords
{
    protected static string $resource = CardTransactionResource::class;
}

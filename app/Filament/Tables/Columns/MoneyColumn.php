<?php

namespace App\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

/**
 * Table column for an integer amount of cents, rendered as "€ 3.50"
 * (euro symbol, a space, then two fixed decimals).
 */
class MoneyColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn (?int $state): ?string => $state !== null ? '€ '.number_format($state / 100, 2, '.', '') : null);
    }
}

<?php

namespace App\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

/**
 * Table column for an integer amount of cents, rendered as "€ 3.50"
 * (euro symbol, a space, then two fixed decimals).
 */
class MoneyColumn extends TextColumn
{
    /**
     * Cents as the panel writes money. Public so a column footer that sums the
     * same cents reads exactly like the column above it.
     */
    public static function euro(?int $cents): ?string
    {
        return $cents !== null ? '€ '.number_format($cents / 100, 2, '.', '') : null;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn (?int $state): ?string => self::euro($state));
    }
}

<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\TextInput;

/**
 * Euro text input backed by an integer amount of cents in the database.
 * The field displays/edits euros with two fixed decimals while storing
 * (and returning) cents.
 *
 * It deliberately avoids ->numeric(), which would render a native number
 * input (dropping trailing zeros) and apply a NumberStateCast that undoes
 * the formatted string. Numeric validation is added manually instead.
 */
class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix('€')
            ->inputMode('decimal')
            // Accept a positive amount with up to two decimals, using dot or comma.
            // A plain `numeric` rule would reject the comma before dehydration.
            ->rule('regex:/^\d+([.,]\d{1,2})?$/')
            ->validationMessages(['regex' => 'Inserisci un importo valido, es. 3,50.'])
            ->formatStateUsing(fn (?int $state): ?string => $state !== null ? number_format($state / 100, 2, '.', '') : null)
            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) str_replace(',', '.', (string) $state)) * 100));
    }
}

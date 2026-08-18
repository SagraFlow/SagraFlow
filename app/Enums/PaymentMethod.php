<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Card = 'card';

    /**
     * Nothing was taken, because there was nothing to take: an order fully
     * covered by a discount. Kept apart from cash on purpose - counted as cash
     * it would open the drawer for no money and muddy the till's own count.
     */
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Contanti',
            self::Card => 'Carta',
            self::None => 'Omaggio',
        };
    }
}

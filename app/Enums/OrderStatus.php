<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Open = 'open';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Aperto',
            self::Paid => 'Pagato',
            self::Cancelled => 'Annullato',
        };
    }
}

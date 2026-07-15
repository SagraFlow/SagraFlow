<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Card = 'card';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Contanti',
            self::Card => 'Carta',
        };
    }
}

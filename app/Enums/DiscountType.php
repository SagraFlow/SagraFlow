<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasLabel
{
    /** A fixed amount off the order total, in cents. */
    case Fixed = 'fixed';

    /** A percentage off the order subtotal (0-100). */
    case Percentage = 'percentage';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Importo fisso',
            self::Percentage => 'Percentuale',
        };
    }
}

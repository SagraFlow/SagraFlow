<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What an account is allowed to do. Two roles and no more, deliberately: a till
 * account and an account that runs the sagra. A middle role would mean deciding,
 * for every resource in the panel, which of the two it belongs to - and keeping
 * that decision current - which is only worth it once there is somebody trusted
 * with the evening but not with the prices.
 */
enum UserRole: string implements HasColor, HasLabel
{
    /** Everything: menu, prices, printers, days, takings, accounts. */
    case Administrator = 'administrator';

    /** The till and nothing else: the panel is closed to them. */
    case Cashier = 'cashier';

    public function getLabel(): string
    {
        return match ($this) {
            self::Administrator => 'Amministratore',
            self::Cashier => 'Cassiere',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Administrator => 'warning',
            self::Cashier => 'gray',
        };
    }
}

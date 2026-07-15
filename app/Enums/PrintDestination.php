<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PrintDestination: string implements HasLabel
{
    /**
     * A fixed department printer chosen from the registry (e.g. kitchen, bar).
     */
    case DepartmentPrinter = 'department_printer';

    /**
     * The local printer of the cash register that rings up the order,
     * resolved at runtime rather than configured up front.
     */
    case CashRegister = 'cash_register';

    public function getLabel(): string
    {
        return match ($this) {
            self::DepartmentPrinter => 'Stampante di reparto',
            self::CashRegister => 'Cassa (stampante locale)',
        };
    }

    public function requiresPrinter(): bool
    {
        return $this === self::DepartmentPrinter;
    }
}

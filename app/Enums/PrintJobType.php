<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PrintJobType: string implements HasLabel
{
    /** Customer receipt printed at the ordering register's local printer. */
    case CustomerReceipt = 'customer_receipt';

    /** Preparation ticket (comanda) printed at a department/station printer. */
    case DepartmentTicket = 'department_ticket';

    /** Customer claim stub (tagliandino) exchanged at a counter to collect the goods. */
    case PickupStub = 'pickup_stub';

    public function getLabel(): string
    {
        return match ($this) {
            self::CustomerReceipt => 'Scontrino',
            self::DepartmentTicket => 'Comanda',
            self::PickupStub => 'Tagliandino di ritiro',
        };
    }

    /**
     * Document types selectable on a print route (the receipt is always
     * printed automatically at the register, so it is not a routable choice).
     *
     * @return array<string, string>
     */
    public static function routableOptions(): array
    {
        return [
            self::DepartmentTicket->value => self::DepartmentTicket->getLabel(),
            self::PickupStub->value => self::PickupStub->getLabel(),
        ];
    }
}

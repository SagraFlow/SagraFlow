<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The result code the terminal returns for a payment. These are the values of
 * the protocol, not ours: they are stored as they arrive so a transaction can
 * always be read back the way the terminal reported it.
 */
enum CardPaymentOutcome: string implements HasColor, HasLabel
{
    case Approved = '00';

    case Declined = '01';

    case CardNotPresent = '05';

    case UnknownTag = '09';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => 'Approvato',
            self::Declined => 'Rifiutato',
            self::CardNotPresent => 'Carta non presente',
            self::UnknownTag => 'Richiesta non riconosciuta',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Declined => 'danger',
            self::CardNotPresent, self::UnknownTag => 'warning',
        };
    }

    /** Whether the money was actually taken. Only this may close an order. */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }
}

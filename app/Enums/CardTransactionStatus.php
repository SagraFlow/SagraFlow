<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a card payment attempt stands.
 *
 * Failed and Unknown are kept apart on purpose, and the difference is the whole
 * point of this enum: Failed means the money was certainly not taken (we never
 * managed to ask), while Unknown means we asked and never heard back - the
 * customer may well have paid. One is a dead end to be retried, the other is a
 * question to be answered before anything else happens.
 */
enum CardTransactionStatus: string implements HasColor, HasLabel
{
    /** Sent, or about to be: the customer is on the terminal. */
    case Pending = 'pending';

    case Approved = 'approved';

    /** The terminal answered, and the answer was no. */
    case Declined = 'declined';

    /** Never left: unreachable terminal, refused message, nothing charged. */
    case Failed = 'failed';

    /** Asked and never answered. The one that must not be guessed. */
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'In corso',
            self::Approved => 'Approvato',
            self::Declined => 'Rifiutato',
            self::Failed => 'Non riuscito',
            self::Unknown => 'Esito sconosciuto',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Approved => 'success',
            self::Declined => 'danger',
            self::Failed => 'gray',
            self::Unknown => 'warning',
        };
    }

    /** Whether the attempt is over, however it ended. */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}

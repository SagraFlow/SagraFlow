<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PrintJobStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Held = 'held';
    case Printed = 'printed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'In coda',
            self::Sending => 'In stampa',
            self::Held => 'In attesa',
            self::Printed => 'Stampato',
            self::Failed => 'Fallito',
            self::Cancelled => 'Annullato',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sending => 'info',
            self::Held => 'warning',
            self::Printed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}

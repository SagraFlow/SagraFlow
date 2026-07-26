<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PrinterStatus: string implements HasColor, HasLabel
{
    /** Reachable and reporting ready. */
    case Ready = 'ready';

    /** Reachable but out of paper. */
    case PaperOut = 'paper_out';

    /** Reachable but the cover/roll bay is open. */
    case CoverOpen = 'cover_open';

    /** Reachable but reporting a mechanical/other error. */
    case Error = 'error';

    /** Unreachable (powered off, unplugged, network down). */
    case Offline = 'offline';

    /** Never probed yet (initial state before the first health poll). */
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ready => 'Pronta',
            self::PaperOut => 'Carta esaurita',
            self::CoverOpen => 'Coperchio aperto',
            self::Error => 'Errore',
            self::Offline => 'Offline',
            self::Unknown => 'Sconosciuto',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::PaperOut, self::CoverOpen => 'warning',
            self::Error, self::Offline => 'danger',
            self::Unknown => 'gray',
        };
    }

    /**
     * Whether a job may be transmitted now. Unknown is optimistic: the printer
     * is reachable but does not report status, so we do not block it.
     */
    public function canPrint(): bool
    {
        return $this === self::Ready || $this === self::Unknown;
    }
}

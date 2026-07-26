<?php

namespace App\Printing;

use App\Enums\PrinterStatus;

/**
 * Translates the raw bytes returned by the ESC/POS real-time status queries
 * (DLE EOT n) into a PrinterStatus. Pure and side-effect free so it can be
 * unit-tested against known status bytes.
 */
class PrinterStatusParser
{
    /** DLE EOT n=1 (printer status): bit 3 set = printer offline. */
    private const OFFLINE = 0x08;

    /** DLE EOT n=2 (offline cause). */
    private const COVER_OPEN = 0x04;      // bit 2

    private const PAPER_END_STOP = 0x20;  // bit 5: stopped because paper ended

    private const ERROR_OCCURRED = 0x40;  // bit 6

    /** DLE EOT n=4 (paper roll sensor): bits 5+6 set = paper end. */
    private const PAPER_END = 0x60;

    /**
     * @param  array<int, int|null>  $bytes  response byte per DLE EOT n (keys 1, 2, 4); null = no reply
     */
    public function parse(array $bytes): PrinterStatus
    {
        $printer = $bytes[1] ?? null;
        $offlineCause = $bytes[2] ?? null;
        $paper = $bytes[4] ?? null;

        // No reply to any query. On a (compliant) Epson printer, real-time
        // status is answered whenever the printer is reachable and readable, so
        // silence means it is offline / in an error state - treat it as Offline
        // (not printable) rather than optimistically assuming it is fine.
        if ($printer === null && $offlineCause === null && $paper === null) {
            return PrinterStatus::Offline;
        }

        if (($paper !== null && ($paper & self::PAPER_END) === self::PAPER_END)
            || ($offlineCause !== null && ($offlineCause & self::PAPER_END_STOP) !== 0)) {
            return PrinterStatus::PaperOut;
        }

        if ($offlineCause !== null && ($offlineCause & self::COVER_OPEN) !== 0) {
            return PrinterStatus::CoverOpen;
        }

        if ($offlineCause !== null && ($offlineCause & self::ERROR_OCCURRED) !== 0) {
            return PrinterStatus::Error;
        }

        // Offline bit set without a specific cause reported.
        if ($printer !== null && ($printer & self::OFFLINE) !== 0) {
            return PrinterStatus::Error;
        }

        return PrinterStatus::Ready;
    }
}

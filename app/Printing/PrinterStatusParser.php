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

        // No reply to any query, which is not the same as being offline: a
        // printer whose receive buffer is full stops answering even the
        // real-time queries, and that is what a printer working through an order
        // of thirty pickup stubs looks like. Reading trouble into that silence
        // announced a healthy printer as offline in the middle of every long
        // print. A printer that is really in trouble says so (it needs memory
        // switch Msw1-3 on, which the panel checks and sets), and one that is
        // switched off or unplugged refuses the connection instead. So silence
        // is Unknown: nothing to alert about, and printable - because the bytes
        // sit in the buffer and come out as soon as the paper moves again.
        if ($printer === null && $offlineCause === null && $paper === null) {
            return PrinterStatus::Unknown;
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

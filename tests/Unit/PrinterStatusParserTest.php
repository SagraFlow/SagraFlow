<?php

use App\Enums\PrinterStatus;
use App\Printing\PrinterStatusParser;

it('parses the DLE EOT status bytes into a printer status', function (array $bytes, PrinterStatus $expected) {
    expect((new PrinterStatusParser)->parse($bytes))->toBe($expected);
})->with([
    'ready' => [[1 => 0x16, 2 => 0x12, 4 => 0x12], PrinterStatus::Ready],
    'paper end (sensor)' => [[1 => 0x16, 2 => 0x12, 4 => 0x60], PrinterStatus::PaperOut],
    'paper end (offline cause)' => [[1 => 0x1A, 2 => 0x20, 4 => 0x12], PrinterStatus::PaperOut],
    'cover open' => [[1 => 0x1A, 2 => 0x04, 4 => 0x12], PrinterStatus::CoverOpen],
    'mechanical error' => [[1 => 0x1A, 2 => 0x40, 4 => 0x12], PrinterStatus::Error],
    'offline without a specific cause' => [[1 => 0x1A, 2 => 0x12, 4 => 0x12], PrinterStatus::Error],
    'paper wins over cover' => [[1 => 0x1A, 2 => 0x24, 4 => 0x12], PrinterStatus::PaperOut],
    // Silence is not trouble: a printer working through a full receive buffer
    // answers nothing, and one that is really in trouble says what is wrong.
    'no reply at all (busy, or never asked before)' => [[1 => null, 2 => null, 4 => null], PrinterStatus::Unknown],
]);

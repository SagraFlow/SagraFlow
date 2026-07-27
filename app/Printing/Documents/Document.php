<?php

namespace App\Printing\Documents;

use App\Settings\EventSettings;
use Illuminate\Support\Carbon;
use Mike42\Escpos\PrintConnectors\MemoryPrintConnector;
use Mike42\Escpos\Printer;

/**
 * Base ESC/POS document. Subclasses describe their content in build(); render()
 * captures the raw ESC/POS bytes in memory so documents can be generated (and
 * tested) without any physical printer.
 */
abstract class Document
{
    /** Character columns for an 80mm receipt at font A. */
    protected const WIDTH = 48;

    abstract protected function build(Printer $printer): void;

    public function render(): string
    {
        $connector = new MemoryPrintConnector;
        $printer = new Printer($connector);

        $this->build($printer);

        $data = $connector->getData();
        $printer->close();

        return $data;
    }

    /**
     * A left/right justified line padded to the paper width, wrapping onto two
     * lines when the two sides would not fit together.
     */
    protected function columns(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);
        $gap = static::WIDTH - mb_strlen($left) - mb_strlen($right);

        if ($gap < 1) {
            return $left."\n".str_pad($right, static::WIDTH, ' ', STR_PAD_LEFT)."\n";
        }

        return $left.str_repeat(' ', $gap).$right."\n";
    }

    protected function divider(): string
    {
        // Box-drawing horizontal line (U+2500); escpos-php encodes it to 0xC4
        // (PC437/850/858) for a continuous rule on Epson-compatible printers.
        return str_repeat("\u{2500}", static::WIDTH)."\n";
    }

    /**
     * Draws a top box: a box-drawing border around an optional 1x1 label over a
     * 2x2 value, bold and centered. Everything sits on the font A grid (each
     * cell 12 dots wide) so the vertical borders stay aligned row by row; each
     * row's line spacing is set to its own height so the border rectangle is
     * continuous. A null label omits the label row entirely, so the box holds
     * just the value between its borders (no blank space above it).
     */
    protected function drawTopBox(Printer $printer, ?string $label, string $value): void
    {
        // Interior width in font A columns: 10 by default, but never less than
        // the 2x2 value (2 columns per character) plus a column of margin each
        // side, so a long value stays inside the border and centered.
        $inner = max(10, mb_strlen($value) * 2 + 2);
        $vertical = "\u{2502}";       // border side
        $horizontal = "\u{2500}";     // border top/bottom

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);

        // Top border.
        $printer->setLineSpacing(24);
        $printer->text("\u{250C}".str_repeat($horizontal, $inner)."\u{2510}\n");

        // Label row (1x1), centered within the border - omitted when null.
        if ($label !== null) {
            $printer->text($vertical.str_pad($label, $inner, ' ', STR_PAD_BOTH).$vertical."\n");
        }

        // Value row: the 2x2 text with single-width side padding so it centres
        // at single-column resolution; the side borders are double height to
        // match the row.
        $padding = max(0, $inner - mb_strlen($value) * 2);
        $leftPad = intdiv($padding, 2);
        $printer->setLineSpacing(48);
        $printer->setTextSize(1, 2);
        $printer->text($vertical.str_repeat(' ', $leftPad));
        $printer->setTextSize(2, 2);
        $printer->text($value);
        $printer->setTextSize(1, 2);
        $printer->text(str_repeat(' ', $padding - $leftPad).$vertical."\n");
        $printer->setTextSize(1, 1);

        // Bottom border.
        $printer->setLineSpacing(24);
        $printer->text("\u{2514}".str_repeat($horizontal, $inner)."\u{2518}\n");

        $printer->setLineSpacing();
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->feed(1);
    }

    protected function money(int $cents): string
    {
        return '€ '.number_format($cents / 100, 2, ',', '.');
    }

    /**
     * Formats a UTC timestamp in the event's configured timezone, empty when null.
     */
    protected function localDateTime(?Carbon $dateTime, string $format = 'd/m/Y H:i'): string
    {
        if ($dateTime === null) {
            return '';
        }

        return $dateTime->copy()
            ->setTimezone(app(EventSettings::class)->timezone)
            ->format($format);
    }
}

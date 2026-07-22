<?php

namespace App\Printing\Documents;

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

    protected function money(int $cents): string
    {
        return '€ '.number_format($cents / 100, 2, ',', '.');
    }
}

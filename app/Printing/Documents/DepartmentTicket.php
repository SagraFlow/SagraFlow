<?php

namespace App\Printing\Documents;

use App\Models\Order;
use Mike42\Escpos\Printer;

/**
 * Preparation ticket (comanda) printed at a department/station printer: a large
 * order number on top and the items printed large between two rules, no prices.
 */
class DepartmentTicket extends Document
{
    /**
     * @param  array<int, array{name: string, quantity: int, deviation: string, note: ?string}>  $items
     */
    public function __construct(
        private Order $order,
        private array $items,
    ) {}

    protected function build(Printer $printer): void
    {
        $order = $this->order;

        // Table box at the top: a box-drawing border around the "TAVOLO" label
        // (1x1) over the table number (2x2), black on white and bold. Everything
        // sits on the font A grid (each cell 12 dots wide) so the vertical
        // borders stay aligned row by row; each row's line spacing is set to its
        // own height so the border rectangle is continuous.
        if ($order->table_number !== null) {
            $number = (string) $order->table_number;
            // Interior width in font A columns: 10 by default, but never less
            // than the 2x2 number (2 columns per digit) plus a column of margin
            // each side, so a long number stays inside the border and centered.
            $inner = max(10, mb_strlen($number) * 2 + 2);
            $vertical = "\u{2502}";       // border side
            $horizontal = "\u{2500}";     // border top/bottom

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);

            // Top border.
            $printer->setLineSpacing(24);
            $printer->text("\u{250C}".str_repeat($horizontal, $inner)."\u{2510}\n");

            // Label row (1x1), centered within the border.
            $printer->text($vertical.str_pad('Tavolo', $inner, ' ', STR_PAD_BOTH).$vertical."\n");

            // Number row: the 2x2 digits with single-width side padding so the
            // number centres at single-column resolution; the side borders are
            // double height to match the row.
            $padding = max(0, $inner - mb_strlen($number) * 2);
            $leftPad = intdiv($padding, 2);
            $printer->setLineSpacing(48);
            $printer->setTextSize(1, 2);
            $printer->text($vertical.str_repeat(' ', $leftPad));
            $printer->setTextSize(2, 2);
            $printer->text($number);
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

        // Details section (order number, customer). Covers are not shown here:
        // they are a standalone print subject with their own routed ticket. When
        // there is no table box above, the ticket starts writing straight away.
        $printer->text($this->columns('N. Ordine', "#{$order->number}"));
        if ($order->customer_name) {
            $printer->text($this->columns('Cliente', $order->customer_name));
        }

        // Items between two separators, printed large (2x2).
        $printer->text($this->divider());
        foreach ($this->items as $item) {
            $printer->setEmphasis(true);
            $printer->setTextSize(2, 2);
            $printer->text("{$item['quantity']}x {$item['name']}\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
            if ($item['deviation'] !== '') {
                $printer->text('   '.$item['deviation']."\n");
            }
            if (! empty($item['note'])) {
                $printer->text('   "'.$item['note']."\"\n");
            }
        }
        $printer->text($this->divider());

        // Footer: date/time on the left, order number on the right (like the receipt).
        $printer->feed(1);
        $printer->text($this->columns($this->localDateTime($order->paid_at), "#{$order->number}"));

        $printer->feed(2);
        $printer->cut();
    }
}

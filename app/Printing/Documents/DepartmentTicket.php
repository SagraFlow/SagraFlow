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

        // Order number, large and bold, centered.
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("#{$order->number}\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        // Details section (table, customer, covers).
        $printer->feed(1);
        if ($order->table_number !== null) {
            $printer->text($this->columns('Tavolo', (string) $order->table_number));
        }
        if ($order->customer_name) {
            $printer->text($this->columns('Cliente', $order->customer_name));
        }
        if (($order->covers ?? 0) > 0) {
            $printer->text($this->columns('Coperti', (string) $order->covers));
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
        $printer->text($this->columns($order->paid_at?->format('d/m/Y H:i') ?? '', "#{$order->number}"));

        $printer->feed(2);
        $printer->cut();
    }
}

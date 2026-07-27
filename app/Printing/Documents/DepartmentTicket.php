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

        // Top box: the table number for table service, or a large "Ritiro"
        // banner for a pickup order (no table number), so a runner can tell at a
        // glance whether the order goes to a table or is collected at a counter.
        if ($order->table_number !== null) {
            $this->drawTopBox($printer, 'Tavolo', (string) $order->table_number);
        } else {
            $this->drawTopBox($printer, null, 'Ritiro');
        }

        // Details section (customer only). Covers are not shown here: they are
        // a standalone print subject with their own routed ticket. The order
        // number is not repeated here - it is in the footer.
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

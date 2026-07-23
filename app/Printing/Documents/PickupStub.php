<?php

namespace App\Printing\Documents;

use App\Models\Order;
use Mike42\Escpos\Printer;

/**
 * Customer pickup stub (tagliandino): a claim ticket the customer hands over at
 * a counter to collect the goods. Laid out like the receipt: event name on top,
 * an order-details section, and the products printed large between two rules.
 */
class PickupStub extends Document
{
    /**
     * @param  array<int, array{name: string, quantity: int, deviation: string, note: ?string}>  $items
     */
    public function __construct(
        private Order $order,
        private string $eventName,
        private string $station,
        private array $items,
    ) {}

    protected function build(Printer $printer): void
    {
        $order = $this->order;

        // Header: event name (double height, single width), centered.
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if ($this->eventName !== '') {
            $printer->setEmphasis(true);
            $printer->setTextSize(1, 2);
            $printer->text($this->eventName."\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
        }
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        // Details section, like the receipt.
        $printer->feed(2);
        $printer->text($this->columns('N. Ordine', "#{$order->number}"));
        $printer->text($this->columns('Ritiro', $this->station));
        if ($order->table_number !== null) {
            $printer->text($this->columns('Tavolo', (string) $order->table_number));
        }
        if ($order->customer_name) {
            $printer->text($this->columns('Cliente', $order->customer_name));
        }

        // Products between two separators, printed large (2x2).
        $printer->text($this->divider());
        foreach ($this->items as $item) {
            $printer->setTextSize(2, 2);
            $printer->text("{$item['quantity']}x {$item['name']}\n");
            $printer->setTextSize(1, 1);
            if ($item['deviation'] !== '') {
                $printer->text('   '.$item['deviation']."\n");
            }
            if (! empty($item['note'])) {
                $printer->text('   "'.$item['note']."\"\n");
            }
        }
        $printer->text($this->divider());

        // Footer: date/time on the left, order number on the right.
        $printer->feed(1);
        $printer->text($this->columns($order->paid_at?->format('d/m/Y H:i') ?? '', "#{$order->number}"));

        $printer->feed(2);
        $printer->cut();
    }
}

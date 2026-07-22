<?php

namespace App\Printing\Documents;

use App\Models\Order;
use Mike42\Escpos\Printer;

/**
 * Customer pickup stub (tagliandino): a claim ticket the customer hands over at
 * a counter to collect the goods. Prominent order number for matching, the
 * station to collect from, items and quantities, no prices.
 */
class PickupStub extends Document
{
    /**
     * @param  array<int, array{name: string, quantity: int, deviation: string, note: ?string}>  $items
     */
    public function __construct(
        private Order $order,
        private string $station,
        private array $items,
    ) {}

    protected function build(Printer $printer): void
    {
        $order = $this->order;

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text('RITIRO '.mb_strtoupper($this->station)."\n");
        $printer->setEmphasis(false);
        $printer->setTextSize(2, 2);
        $printer->text('#'.$order->number."\n");
        $printer->setTextSize(1, 1);
        $printer->text(($order->paid_at?->format('d/m/Y H:i') ?? '')."\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->divider());

        foreach ($this->items as $item) {
            $printer->setEmphasis(true);
            $printer->text("{$item['quantity']}x {$item['name']}\n");
            $printer->setEmphasis(false);
            if ($item['deviation'] !== '') {
                $printer->text('   '.$item['deviation']."\n");
            }
            if (! empty($item['note'])) {
                $printer->text('   "'.$item['note']."\"\n");
            }
        }

        $printer->feed(2);
        $printer->cut();
    }
}

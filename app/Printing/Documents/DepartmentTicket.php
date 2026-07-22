<?php

namespace App\Printing\Documents;

use App\Models\Order;
use Mike42\Escpos\Printer;

/**
 * Preparation ticket (comanda) printed at a department/station printer:
 * items and quantities with their customizations, no prices.
 */
class DepartmentTicket extends Document
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
        $printer->text(mb_strtoupper($this->station)."\n");
        $printer->setEmphasis(false);
        $printer->text("Ordine #{$order->number}\n");
        $printer->text(($order->paid_at?->format('d/m/Y H:i') ?? '')."\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->divider());

        $printer->text($this->columns('Servizio', $order->service_type?->getLabel() ?? '-'));
        if ($order->table_number !== null) {
            $printer->text($this->columns('Tavolo', (string) $order->table_number));
        }
        if ($order->covers) {
            $printer->text($this->columns('Coperti', (string) $order->covers));
        }
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

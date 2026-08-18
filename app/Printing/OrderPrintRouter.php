<?php

namespace App\Printing;

use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Printer;
use App\Models\PrintRoute;
use App\Settings\EventSettings;

/**
 * Resolves an order into the set of documents to print and where. The customer
 * receipt always goes to the ordering register; comande and pickup stubs are
 * driven entirely by each category's print routes for the order's service type,
 * one ticket per category (grouped) or one per portion (ungrouped).
 */
class OrderPrintRouter
{
    /** Label and line name for the standalone covers ticket. */
    private const COVERS_LABEL = 'Coperti';

    /**
     * @return array<int, PrintTask>
     */
    public function tasks(Order $order): array
    {
        $registerPrinter = $this->active($order->cashRegister?->printer);
        $settings = app(EventSettings::class);

        $tasks = [
            new PrintTask(
                $registerPrinter,
                PrintJobType::CustomerReceipt,
                'Scontrino',
                // The drawer is not this document's business: a cash sale opens
                // it when its payment screen opens, long before the receipt is
                // printed, and by then the cashier has usually closed it again.
                ['eventName' => $settings->eventName],
            ),
        ];

        $linesByCategory = $order->lines->groupBy(fn (OrderLine $line): int|string => $line->food?->category?->id ?? 'none');

        foreach ($linesByCategory as $lines) {
            $category = $lines->first()->food?->category;

            if ($category === null) {
                continue;
            }

            $routes = $category->printRoutes
                ->where('service_type', $order->service_type)
                ->sortBy('position');

            foreach ($routes as $route) {
                $printer = $route->destination === PrintDestination::CashRegister
                    ? $registerPrinter
                    : $this->active($route->printer);

                if ($route->grouped) {
                    $tasks[] = $this->task($route, $printer, $category->name, $settings->eventName, $lines->map(
                        fn (OrderLine $line): array => $this->item($line, $line->quantity),
                    )->all());
                } else {
                    foreach ($lines as $line) {
                        foreach (range(1, $line->quantity) as $ignored) {
                            $tasks[] = $this->task($route, $printer, $category->name, $settings->eventName, [$this->item($line, 1)]);
                        }
                    }
                }
            }
        }

        // Covers (coperti) are a standalone print subject: when the order has
        // covers, each covers route for this service type prints its own ticket
        // with a single "N Coperti" line, independent of the product categories.
        if (($order->covers ?? 0) > 0) {
            $coverRoutes = PrintRoute::query()
                ->where('for_covers', true)
                ->where('service_type', $order->service_type)
                ->with('printer')
                ->orderBy('position')
                ->get();

            foreach ($coverRoutes as $route) {
                $printer = $route->destination === PrintDestination::CashRegister
                    ? $registerPrinter
                    : $this->active($route->printer);

                $tasks[] = $this->task($route, $printer, self::COVERS_LABEL, $settings->eventName, [
                    ['name' => self::COVERS_LABEL, 'quantity' => $order->covers, 'deviation' => '', 'note' => null],
                ]);
            }
        }

        return $tasks;
    }

    /**
     * @param  array<int, array{name: string, quantity: int, deviation: string, note: ?string}>  $items
     */
    private function task(PrintRoute $route, ?Printer $printer, string $station, string $eventName, array $items): PrintTask
    {
        // The pickup stub needs the event name for its header; the station is
        // not printed (it stays only as the PrintTask/PrintJob label).
        $spec = $route->document === PrintJobType::PickupStub
            ? ['eventName' => $eventName, 'items' => $items]
            : ['items' => $items];

        return new PrintTask($printer, $route->document, $station, $spec);
    }

    private function active(?Printer $printer): ?Printer
    {
        return $printer !== null && $printer->active ? $printer : null;
    }

    /**
     * @return array{name: string, quantity: int, deviation: string, note: ?string}
     */
    private function item(OrderLine $line, int $quantity): array
    {
        return [
            'name' => $line->food_name,
            'quantity' => $quantity,
            'deviation' => $line->deviationSummary(),
            'note' => $line->note,
        ];
    }
}

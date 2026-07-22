<?php

namespace App\Printing;

use App\Enums\PaymentMethod;
use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Printer;
use App\Models\PrintRoute;
use App\Printing\Documents\CustomerReceipt;
use App\Printing\Documents\DepartmentTicket;
use App\Printing\Documents\PickupStub;
use App\Settings\EventSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves an order into the set of documents to print and where. The customer
 * receipt always goes to the ordering register; comande and pickup stubs are
 * driven entirely by each category's print routes for the order's service type,
 * one ticket per category (grouped) or one per portion (ungrouped).
 */
class OrderPrintRouter
{
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
                new CustomerReceipt(
                    $order,
                    $settings->eventName,
                    $order->payment_method === PaymentMethod::Cash,
                    $this->logoPath($settings->logo),
                ),
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
                    $tasks[] = $this->task($order, $route, $printer, $category->name, $lines->map(
                        fn (OrderLine $line): array => $this->item($line, $line->quantity),
                    )->all());
                } else {
                    foreach ($lines as $line) {
                        foreach (range(1, $line->quantity) as $ignored) {
                            $tasks[] = $this->task($order, $route, $printer, $category->name, [$this->item($line, 1)]);
                        }
                    }
                }
            }
        }

        return $tasks;
    }

    /**
     * @param  array<int, array{name: string, quantity: int, deviation: string, note: ?string}>  $items
     */
    private function task(Order $order, PrintRoute $route, ?Printer $printer, string $station, array $items): PrintTask
    {
        $document = $route->document === PrintJobType::PickupStub
            ? new PickupStub($order, $station, $items)
            : new DepartmentTicket($order, $station, $items);

        return new PrintTask($printer, $route->document, $station, $document);
    }

    private function active(?Printer $printer): ?Printer
    {
        return $printer !== null && $printer->active ? $printer : null;
    }

    /**
     * Absolute filesystem path of the receipt logo, or null when unset/missing.
     */
    private function logoPath(?string $logo): ?string
    {
        if ($logo === null || ! Storage::disk('public')->exists($logo)) {
            return null;
        }

        return Storage::disk('public')->path($logo);
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

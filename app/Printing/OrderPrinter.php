<?php

namespace App\Printing;

use App\Enums\PrintJobStatus;
use App\Jobs\SendToPrinterJob;
use App\Models\Order;
use App\Models\PrintJob;

/**
 * Routes an order to its documents, records a PrintJob for each, and queues the
 * network transmission. Reused for the initial print at checkout and for
 * reprints from the admin panel.
 */
class OrderPrinter
{
    public function __construct(private OrderPrintRouter $router) {}

    public function print(Order $order): void
    {
        $order->loadMissing(['lines.ingredients', 'lines.food.category.printRoutes', 'cashRegister.printer']);

        /** @var array<int, PrintJob> $queued */
        $queued = [];

        foreach ($this->router->tasks($order) as $task) {
            $hasPrinter = $task->printer !== null;

            $printJob = PrintJob::create([
                'order_id' => $order->id,
                'printer_id' => $task->printer?->id,
                'printer_name' => $task->printer?->name,
                'type' => $task->type,
                'label' => $task->label,
                'status' => $hasPrinter ? PrintJobStatus::Pending : PrintJobStatus::Failed,
                'error' => $hasPrinter ? null : 'Nessuna stampante attiva per questa destinazione.',
                // Freeze the document spec (not the bytes) so the worker/reconciler
                // can render it at send time from this row + the immutable order.
                'spec' => $task->spec,
                'queued_at' => $hasPrinter ? now() : null,
            ]);

            if ($hasPrinter) {
                $queued[] = $printJob;
            }
        }

        // One send per printer, carrying that printer's documents in order: the
        // receipt and the pickup stubs that follow it are printed back to back
        // instead of each queueing behind the previous one.
        SendToPrinterJob::dispatchForAll($queued);
    }
}

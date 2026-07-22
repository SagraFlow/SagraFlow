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

        foreach ($this->router->tasks($order) as $task) {
            $printJob = PrintJob::create([
                'order_id' => $order->id,
                'printer_id' => $task->printer?->id,
                'printer_name' => $task->printer?->name,
                'type' => $task->type,
                'label' => $task->label,
                'status' => $task->printer !== null ? PrintJobStatus::Pending : PrintJobStatus::Failed,
                'error' => $task->printer !== null ? null : 'Nessuna stampante attiva per questa destinazione.',
            ]);

            if ($task->printer !== null) {
                SendToPrinterJob::dispatch(
                    $printJob->id,
                    $task->printer->ip_address,
                    $task->printer->port,
                    // Base64 so the raw ESC/POS bytes survive JSON queue serialization.
                    base64_encode($task->document->render()),
                );
            }
        }
    }
}

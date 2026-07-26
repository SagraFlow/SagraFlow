<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Printing\PrinterAlerts;
use App\Printing\PrinterConnection;
use App\Printing\PrinterQueue;
use Illuminate\Console\Command;

/**
 * Probes every active printer's status, records it, alerts on problems, and
 * releases held jobs the moment a printer is ready again. Scheduled frequently
 * so problems surface (and recover) within seconds, independent of print traffic.
 */
class PollPrinterHealth extends Command
{
    protected $signature = 'printers:poll';

    protected $description = 'Probe active printers, update health, alert and release held jobs.';

    public function handle(PrinterConnection $connection, PrinterAlerts $alerts, PrinterQueue $queue): int
    {
        $connectTimeout = (int) config('printing.poll.connect_timeout', 2);
        $readTimeoutMs = (int) config('printing.poll.read_timeout_ms', 300);

        foreach (Printer::query()->active()->get() as $printer) {
            $status = $connection->probe($printer->ip_address, $printer->port, $connectTimeout, $readTimeoutMs);
            $printer->recordStatus($status, $status->canPrint() ? null : $status->getLabel());

            $alerts->evaluate($printer);

            if ($status->canPrint()) {
                $queue->release($printer);
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Printing\PrinterAlerts;
use App\Printing\PrinterConnection;
use App\Printing\PrinterLock;
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

    public function handle(PrinterConnection $connection, PrinterAlerts $alerts, PrinterQueue $queue, PrinterLock $lock): int
    {
        $connectTimeout = (int) config('printing.poll.connect_timeout', 2);
        $readTimeoutMs = (int) config('printing.poll.read_timeout_ms', 300);

        foreach (Printer::query()->active()->get() as $printer) {
            // Only the socket work takes the printer, and a printer with a send
            // in flight is skipped entirely: probing it would steal the socket
            // from the print, which reports its own outcome anyway.
            $probed = $lock->run($printer->id, function () use ($connection, $printer, $connectTimeout, $readTimeoutMs): void {
                $status = $connection->probe($printer->ip_address, $printer->port, $connectTimeout, $readTimeoutMs);
                $printer->recordStatus($status, $status->canPrint() ? null : $status->getLabel());
            }, ttl: $connectTimeout + 5);

            if (! $probed) {
                continue;
            }

            $alerts->evaluate($printer);

            // Released after letting go of the printer, so the send that picks
            // the held documents up does not have to wait for this poll.
            if ($printer->status->canPrint()) {
                $queue->release($printer);
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\PrintJobStatus;
use App\Jobs\SendToPrinterJob;
use App\Models\PrintJob;
use Illuminate\Console\Command;

/**
 * Safety net that keeps the DB (the source of truth) and the queue in sync:
 * re-dispatches jobs that never ran, are held for a now-ready printer, or are
 * stuck "Sending" (a worker died mid-send). Recovers lost queue messages so no
 * print is ever silently dropped, even if Redis is restarted.
 */
class ReconcilePrintJobs extends Command
{
    protected $signature = 'print:reconcile';

    protected $description = 'Re-dispatch pending/held/stuck print jobs from the database.';

    public function handle(): int
    {
        $staleSeconds = (int) config('printing.reconcile.stale_seconds', 120);

        $jobs = PrintJob::query()
            ->with('printer')
            ->whereIn('status', [PrintJobStatus::Pending, PrintJobStatus::Sending, PrintJobStatus::Held])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var array<int, PrintJob> $recovered */
        $recovered = [];

        foreach ($jobs as $job) {
            $printer = $job->printer;

            if ($printer === null || ! $printer->active) {
                continue;
            }

            if ($job->status === PrintJobStatus::Sending) {
                // A fresh Sending is a worker mid-send; only reclaim stale ones.
                if ($job->updated_at !== null && $job->updated_at->gt(now()->subSeconds($staleSeconds))) {
                    continue;
                }

                $job->update(['status' => PrintJobStatus::Pending]);
            }

            // A held job waits until its printer is ready again.
            if ($job->status === PrintJobStatus::Held && ! $printer->status->canPrint()) {
                continue;
            }

            $recovered[] = $job;
        }

        // Grouped per printer, in the order they were created: one connection
        // catches up on everything a printer owes.
        SendToPrinterJob::dispatchForAll($recovered);

        return self::SUCCESS;
    }
}

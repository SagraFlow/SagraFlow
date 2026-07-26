<?php

namespace App\Printing;

use App\Enums\PrintJobStatus;
use App\Jobs\SendToPrinterJob;
use App\Models\Printer;
use App\Models\PrintJob;

/**
 * Releases a printer's held jobs back onto the queue once it is ready again,
 * preserving the original order. Re-dispatch is safe against duplicates: the
 * job's atomic claim ignores anything not still Held/Pending.
 */
class PrinterQueue
{
    public function release(Printer $printer): int
    {
        if (! $printer->status->canPrint()) {
            return 0;
        }

        $jobs = PrintJob::query()
            ->where('printer_id', $printer->id)
            ->where('status', PrintJobStatus::Held)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($jobs as $job) {
            SendToPrinterJob::dispatchFor($job);
        }

        return $jobs->count();
    }
}

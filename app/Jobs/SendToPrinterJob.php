<?php

namespace App\Jobs;

use App\Enums\PrinterStatus;
use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Models\PrintJob;
use App\Printing\DocumentFactory;
use App\Printing\PrinterConnection;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transmits a PrintJob's pre-rendered ESC/POS bytes to its printer. The bytes
 * and the outcome live on the PrintJob row (source of truth); the queue only
 * carries the id. A printer that is not ready parks the job as Held for the
 * health poll / reconciler to release, so a print is never silently lost.
 */
class SendToPrinterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Max execution per attempt; kept below the supervisor timeout (60) < retry_after (90). */
    public int $timeout = 30;

    public function __construct(public int $printJobId) {}

    /**
     * Dispatches transmission of a PrintJob onto its priority queue: cash
     * receipts are latency-critical, everything else is normal priority.
     */
    public static function dispatchFor(PrintJob $printJob): void
    {
        $queue = $printJob->type === PrintJobType::CustomerReceipt ? 'receipts' : 'prints';

        self::dispatch($printJob->id)->onQueue($queue);
    }

    /**
     * Bound retries by time rather than count so WithoutOverlapping re-queues
     * (while another job for the same printer runs) never exhaust the tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $printerId = PrintJob::whereKey($this->printJobId)->value('printer_id');

        // One transmission at a time per printer (strict serialization + isolation).
        return [(new WithoutOverlapping('printer:'.$printerId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(PrinterConnection $connection, DocumentFactory $documents): void
    {
        // Eager-load the order's lines so the receipt renders without N+1 lazy
        // loads; comande/coperti render from the spec and ignore these.
        $printJob = PrintJob::with(['printer', 'order.lines.ingredients'])->find($this->printJobId);

        if ($printJob === null || $printJob->status === PrintJobStatus::Printed) {
            return;
        }

        $printer = $printJob->printer;

        if ($printer === null || ! $printer->active) {
            $printJob->update([
                'status' => PrintJobStatus::Failed,
                'error' => 'Nessuna stampante attiva per questa destinazione.',
            ]);

            return;
        }

        // Atomic claim: only one worker transitions Pending/Held -> Sending.
        $claimed = PrintJob::whereKey($printJob->id)
            ->whereIn('status', [PrintJobStatus::Pending, PrintJobStatus::Held])
            ->update(['status' => PrintJobStatus::Sending, 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed === 0) {
            return; // already claimed by another worker, or already finished
        }

        $status = $connection->probe($printer->ip_address, $printer->port);
        $printer->recordStatus($status, $status->canPrint() ? null : $status->getLabel());

        if (! $status->canPrint()) {
            $printJob->update([
                'status' => PrintJobStatus::Held,
                'error' => 'Stampante non pronta: '.$status->getLabel(),
            ]);

            return;
        }

        try {
            $bytes = $documents->make($printJob->type, $printJob->spec ?? [], $printJob->order)->render();
            $connection->send($printer->ip_address, $printer->port, $bytes);
        } catch (Throwable $exception) {
            // Never lose a print: park it for the health poll / reconciler to retry.
            $printer->recordStatus(PrinterStatus::Offline, $exception->getMessage());
            $printJob->update(['status' => PrintJobStatus::Held, 'error' => $exception->getMessage()]);

            return;
        }

        $printJob->update([
            'status' => PrintJobStatus::Printed,
            'printed_at' => now(),
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        PrintJob::whereKey($this->printJobId)->update([
            'status' => PrintJobStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);
    }
}

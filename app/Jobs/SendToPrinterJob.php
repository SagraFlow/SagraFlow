<?php

namespace App\Jobs;

use App\Enums\PrinterStatus;
use App\Enums\PrintJobStatus;
use App\Enums\PrintJobType;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Printing\DocumentFactory;
use App\Printing\PrinterConnection;
use App\Printing\PrinterLock;
use App\Printing\PrinterSession;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transmits a printer's queued documents, in order, over a single connection.
 * One job per printer rather than per document: the receipt, its pickup stubs
 * and any comanda for the same printer leave back to back, so the cashier is
 * not waiting on a queue round trip between one sheet and the next. The bytes
 * are rendered at send time from the PrintJob rows (the source of truth); the
 * queue only carries their ids. A printer that is not ready parks the batch as
 * Held for the health poll / reconciler to release, so a print is never
 * silently lost.
 */
class SendToPrinterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Max execution per attempt; kept below the supervisor timeout (60) < retry_after (90). */
    public int $timeout = 45;

    /** Statuses a document can still be sent from. */
    private const SENDABLE = [PrintJobStatus::Pending, PrintJobStatus::Held];

    /** Status queries before giving up on a printer that answers nothing. */
    private const READY_ATTEMPTS = 3;

    /** Pause between them: a printer mid-document answers again as soon as it drains. */
    private const READY_WAIT_MS = 400;

    /**
     * @param  array<int, int>  $printJobIds  the documents to transmit, in print order
     */
    public function __construct(public int $printerId, public array $printJobIds) {}

    /**
     * Dispatches transmission of a single PrintJob (a reprint, or a document
     * recovered by the reconciler).
     */
    public static function dispatchFor(PrintJob $printJob): void
    {
        self::dispatchForAll([$printJob]);
    }

    /**
     * Dispatches one job per printer, each carrying that printer's documents in
     * the given order. Cash receipts are latency-critical, so a batch that holds
     * one goes on the receipts queue with everything printing alongside it;
     * department comande stay at normal priority.
     *
     * @param  iterable<int, PrintJob>  $printJobs
     */
    public static function dispatchForAll(iterable $printJobs): void
    {
        $batches = Collection::make($printJobs)
            ->filter(fn (PrintJob $printJob): bool => $printJob->printer_id !== null)
            ->groupBy('printer_id');

        foreach ($batches as $printerId => $batch) {
            $queue = $batch->contains(fn (PrintJob $printJob): bool => $printJob->type === PrintJobType::CustomerReceipt)
                ? 'receipts'
                : 'prints';

            self::dispatch((int) $printerId, $batch->pluck('id')->all())->onQueue($queue);
        }
    }

    /**
     * Bound retries by time rather than count so releases (while another job
     * holds the same printer) never exhaust the tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PrinterConnection $connection, DocumentFactory $documents, PrinterLock $lock): void
    {
        $printer = Printer::find($this->printerId);

        if ($printer === null || ! $printer->active) {
            $this->sendable()->update([
                'status' => PrintJobStatus::Failed,
                'error' => 'Nessuna stampante attiva per questa destinazione.',
            ]);

            return;
        }

        // Eager-load each order's lines so a receipt renders without N+1 lazy
        // loads; comande/coperti render from the spec and ignore these.
        $printJobs = $this->sendable()->with(['order.lines.ingredients'])->get()->sortBy(
            fn (PrintJob $printJob): int => (int) array_search($printJob->id, $this->printJobIds),
        )->values();

        if ($printJobs->isEmpty()) {
            return; // already printed, cancelled, or claimed by another worker
        }

        // One printer, one talker: a health probe must not steal the socket.
        $ran = $lock->run($printer->id, fn () => $this->transmit($connection, $documents, $printer, $printJobs));

        if (! $ran) {
            $this->release(1);
        }
    }

    /**
     * Sends every document of the batch over one connection, marking each one
     * printed as it goes.
     */
    private function transmit(PrinterConnection $connection, DocumentFactory $documents, Printer $printer, Collection $printJobs): void
    {
        try {
            $connection->session($printer->ip_address, $printer->port, function (PrinterSession $session) use ($documents, $printer, $printJobs): void {
                $status = $this->readiness($session);
                $printer->recordStatus($status, $status->canPrint() ? null : $status->getLabel());

                if (! $status->canPrint()) {
                    $this->hold($printJobs, 'Stampante non pronta: '.$status->getLabel());

                    return;
                }

                // Status is read once, before the first document: the printer is
                // busy with our own batch from here on, and a document already
                // handed to it prints even if the roll runs out mid-batch, so
                // re-reading would only invite false alarms and reprints.
                foreach ($printJobs as $printJob) {
                    if (! $this->claim($printJob)) {
                        continue;
                    }

                    $session->write($documents->make($printJob->type, $printJob->spec ?? [], $printJob->order)->render());

                    $printJob->update([
                        'status' => PrintJobStatus::Printed,
                        'printed_at' => now(),
                        'sent_at' => now(),
                        'error' => null,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            // Never lose a print: park what is left for the health poll / reconciler.
            $printer->recordStatus(PrinterStatus::Offline, $exception->getMessage());
            $this->hold($printJobs, $exception->getMessage());
        }
    }

    /**
     * The printer's state at the start of a batch. A printer that stays silent
     * is usually still working through the previous order, and the open socket
     * proves it is reachable: ask again a couple of times rather than parking
     * the batch until the next health poll.
     */
    private function readiness(PrinterSession $session): PrinterStatus
    {
        $status = $session->status();

        for ($attempt = 1; $attempt < self::READY_ATTEMPTS && $status === PrinterStatus::Offline; $attempt++) {
            usleep(self::READY_WAIT_MS * 1000);

            $status = $session->status();
        }

        return $status;
    }

    /**
     * Claims a document for this worker: only one transitions it Pending/Held to
     * Sending, so a document is never transmitted twice.
     */
    private function claim(PrintJob $printJob): bool
    {
        $claimed = PrintJob::whereKey($printJob->id)
            ->whereIn('status', self::SENDABLE)
            ->update(['status' => PrintJobStatus::Sending, 'attempts' => DB::raw('attempts + 1')]);

        return $claimed === 1;
    }

    /**
     * Parks whatever the batch did not print, keeping it queued for a retry.
     *
     * @param  Collection<int, PrintJob>  $printJobs
     */
    private function hold(Collection $printJobs, ?string $error): void
    {
        $this->unfinished($printJobs->pluck('id')->all())->update([
            'status' => PrintJobStatus::Held,
            'error' => $error,
        ]);
    }

    /**
     * The batch's documents that are still waiting to be sent.
     *
     * @return Builder<PrintJob>
     */
    private function sendable()
    {
        return PrintJob::query()
            ->whereIn('id', $this->printJobIds)
            ->whereIn('status', self::SENDABLE);
    }

    /**
     * Documents that did not make it out of the printer: waiting to be sent, or
     * claimed by the send that just broke down.
     *
     * @param  array<int, int>  $ids
     * @return Builder<PrintJob>
     */
    private function unfinished(array $ids)
    {
        return PrintJob::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [...self::SENDABLE, PrintJobStatus::Sending]);
    }

    public function failed(?Throwable $exception): void
    {
        // Anything already printed stays printed: only the rest of the batch failed.
        $this->unfinished($this->printJobIds)->update([
            'status' => PrintJobStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);
    }
}

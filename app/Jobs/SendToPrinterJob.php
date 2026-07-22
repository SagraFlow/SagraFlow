<?php

namespace App\Jobs;

use App\Enums\PrintJobStatus;
use App\Models\PrintJob;
use App\Printing\PrinterConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Transmits pre-rendered ESC/POS bytes to a network printer, retrying on
 * failure. The PrintJob row tracks the outcome for diagnostics and reprints.
 */
class SendToPrinterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  string  $encodedData  Base64-encoded ESC/POS bytes (JSON-safe on the queue).
     */
    public function __construct(
        public int $printJobId,
        public string $host,
        public int $port,
        public string $encodedData,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [3, 10];
    }

    public function handle(PrinterConnection $connection): void
    {
        $printJob = PrintJob::find($this->printJobId);

        if ($printJob === null) {
            return;
        }

        $printJob->update(['attempts' => $this->attempts()]);

        $connection->send($this->host, $this->port, base64_decode($this->encodedData));

        $printJob->update([
            'status' => PrintJobStatus::Printed,
            'printed_at' => now(),
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

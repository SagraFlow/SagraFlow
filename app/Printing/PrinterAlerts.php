<?php

namespace App\Printing;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Notifies staff about printer problems, with a policy by error type: offline
 * alerts immediately; a reachable-but-not-ready printer (paper out / cover /
 * error) alerts only after a grace period. Alerts are edge-triggered: they fire
 * once per outage and are re-armed only when the printer recovers, so a
 * persistent fault never re-notifies.
 */
class PrinterAlerts
{
    public function evaluate(Printer $printer): void
    {
        $status = $printer->status;

        if ($status->canPrint()) {
            $this->resolve($printer);

            return;
        }

        // Offline needs attention now; it does not fix itself.
        if ($status === PrinterStatus::Offline) {
            $this->notify($printer, "Stampante {$printer->name} offline");

            return;
        }

        // Paper out / cover open / error: alert only once the state has
        // persisted past the grace period (staff usually fix it in seconds).
        $grace = (int) config('printing.grace_seconds', 60);

        if ($printer->status_changed_at !== null
            && $printer->status_changed_at->lte(now()->subSeconds($grace))) {
            $this->notify($printer, "Stampante {$printer->name}: {$status->getLabel()}");
        }
    }

    public function resolve(Printer $printer): void
    {
        Cache::forget($this->key($printer));
    }

    private function notify(Printer $printer, string $message): void
    {
        // Edge-triggered: fire once per outage. The suppression key has no expiry
        // and is cleared only by resolve() when the printer recovers, re-arming
        // the alert — so a persistent fault never re-notifies.
        if (! Cache::add($this->key($printer), true)) {
            return;
        }

        $recipients = User::all();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Problema di stampa')
            ->body($message)
            ->sendToDatabase($recipients);
    }

    private function key(Printer $printer): string
    {
        return "printer-alert:{$printer->id}";
    }
}

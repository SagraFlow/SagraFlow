<?php

use App\Enums\PrintJobStatus;
use App\Models\CashRegister;
use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Printer trouble badge for the till.
 *
 * A component of its own so its polling costs a couple of queries on a tiny
 * view: polling is per component, so leaving it inside the till would re-render
 * the whole menu every few seconds to refresh a badge that changes twice an
 * evening.
 */
new class extends Component
{
    #[Locked]
    public ?int $cashRegisterId = null;

    public bool $showIssues = false;

    #[Computed]
    public function cashRegister(): ?CashRegister
    {
        return $this->cashRegisterId !== null
            ? CashRegister::active()->find($this->cashRegisterId)
            : null;
    }

    /**
     * A warning for the cashier about the printers relevant to their work, or
     * null when all is well.
     *
     * The message is kept short: it rides in a badge in the header (never in a
     * band above the menu, which would resize the grid under the cashier's
     * fingers), so it names the most relevant printer and counts the rest.
     *
     * @return array{level: string, message: string}|null
     */
    #[Computed]
    public function alert(): ?array
    {
        $printers = $this->relevantPrinters();

        if ($printers->isEmpty()) {
            return null;
        }

        $inError = $printers->filter(fn (Printer $printer): bool => ! $printer->status->canPrint());

        if ($inError->isNotEmpty()) {
            // The first one is this register's own printer when it is among them,
            // otherwise the alphabetically first department printer.
            $worst = $inError->first();
            $others = $inError->count() - 1;

            return [
                'level' => 'danger',
                'message' => "{$worst->name}: ".mb_strtolower($worst->status->getLabel()).($others > 0 ? " +{$others}" : ''),
            ];
        }

        // Only Held jobs (waiting to retry) are actionable; Failed are terminal
        // and belong to the admin log, not a permanent banner on the till.
        $waiting = PrintJob::query()
            ->whereIn('printer_id', $printers->pluck('id'))
            ->where('status', PrintJobStatus::Held)
            ->count();

        if ($waiting > 0) {
            return ['level' => 'warning', 'message' => "{$waiting} in attesa"];
        }

        return null;
    }

    /**
     * The printers this cashier's work depends on: their own register's printer
     * plus every shared department printer, their own first and the rest
     * alphabetically. Other registers' printers are none of their business.
     *
     * @return Collection<int, Printer>
     */
    protected function relevantPrinters(): Collection
    {
        $registerPrinterId = $this->cashRegister?->printer_id;

        return Printer::query()
            ->active()
            ->where(function ($query) use ($registerPrinterId): void {
                $query->whereDoesntHave('cashRegister'); // department printers

                if ($registerPrinterId !== null) {
                    $query->orWhere('id', $registerPrinterId); // this register's own
                }
            })
            ->get()
            ->sortBy(fn (Printer $printer): array => [
                $printer->id === $registerPrinterId ? 0 : 1,
                $printer->name,
            ])
            ->values();
    }

    /**
     * Every relevant printer that is either unable to print or sitting on jobs
     * waiting to retry, in the same order as the badge. The full picture the
     * badge has no room for, shown when the cashier taps it.
     *
     * @return array<int, array{name: string, status: string, blocked: bool, waiting: int}>
     */
    #[Computed]
    public function issues(): array
    {
        $printers = $this->relevantPrinters();

        if ($printers->isEmpty()) {
            return [];
        }

        $waitingByPrinter = PrintJob::query()
            ->whereIn('printer_id', $printers->pluck('id'))
            ->where('status', PrintJobStatus::Held)
            ->selectRaw('printer_id, count(*) as total')
            ->groupBy('printer_id')
            ->pluck('total', 'printer_id');

        return $printers
            ->map(fn (Printer $printer): array => [
                'name' => $printer->name,
                'status' => $printer->status->getLabel(),
                'blocked' => ! $printer->status->canPrint(),
                'waiting' => (int) ($waitingByPrinter[$printer->id] ?? 0),
            ])
            ->filter(fn (array $issue): bool => $issue['blocked'] || $issue['waiting'] > 0)
            ->values()
            ->all();
    }

    public function openIssues(): void
    {
        $this->showIssues = true;
    }

    public function closeIssues(): void
    {
        $this->showIssues = false;
    }
};
?>

<div wire:poll.5s>
    @if ($alert = $this->alert)
        <button
            type="button"
            wire:click="openIssues"
            title="Stato stampanti"
            @class([
                'flex max-w-64 items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold text-white',
                'bg-red-600 pos-alert-pulse' => $alert['level'] === 'danger',
                'bg-amber-500' => $alert['level'] === 'warning',
            ])
        >
            <x-heroicon-o-printer class="h-4 w-4 shrink-0" />
            <span class="truncate">{{ $alert['message'] }}</span>
        </button>
    @endif

    @if ($showIssues)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-left shadow-xl dark:bg-neutral-900">
                <h2 class="text-xl font-semibold">Stato stampanti</h2>

                @if ($this->issues === [])
                    {{-- The list refreshes while open: say so instead of yanking the
                         modal away from under the cashier when everything recovers. --}}
                    <p class="mt-2 text-neutral-500">Nessun problema in corso.</p>
                @else
                    <ul class="mt-4 space-y-2">
                        @foreach ($this->issues as $issue)
                            <li class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-800">
                                <span class="min-w-0 truncate font-medium">{{ $issue['name'] }}</span>
                                <span class="flex shrink-0 items-center gap-2 text-sm">
                                    @if ($issue['blocked'])
                                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $issue['status'] }}</span>
                                    @endif
                                    @if ($issue['waiting'] > 0)
                                        <span class="text-amber-600 dark:text-amber-400">{{ $issue['waiting'] }} in attesa</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <button type="button" wire:click="closeIssues" class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                    Chiudi
                </button>
            </div>
        </div>
    @endif
</div>

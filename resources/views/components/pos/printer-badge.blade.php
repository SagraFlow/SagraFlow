<?php

use App\Console\Commands\PollPrinterHealth;
use App\Enums\PrintJobStatus;
use App\Models\CashRegister;
use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 *
 * It answers one question for the cashier: is anything not coming out of a
 * printer? Retries and recovery happen on their own and need nobody watching;
 * what needs a person is a document that will not print by itself, and there is
 * nobody but the cashier standing there to notice. So a queue that has stopped
 * moving, a document given up on, and a monitoring that has died all show here -
 * silence on this badge has to mean silence in the room, not that we are not
 * looking.
 */
new class extends Component
{
    /**
     * A document still queued this long after being handed over means nothing is
     * moving: the workers are down, or the printer is holding the lock forever.
     * Seconds, because in a working system a document leaves in about one.
     */
    private const STUCK_AFTER_SECONDS = 30;

    /**
     * How long a document given up on stays on the badge. It is an alert, not a
     * ledger: it exists to catch an eye while the order is still on the counter,
     * and the print jobs list in the panel keeps the history either way.
     */
    private const FAILED_FOR_MINUTES = 10;

    /** No poll for this long and nobody is checking the printers. */
    private const MONITORING_STALE_SECONDS = 60;

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

        // Given up on: nothing will print these unless somebody asks. Loudest of
        // the lot, because the kitchen is waiting for a comanda that is not coming.
        if (($failed = $this->totals('failed')) > 0) {
            return ['level' => 'danger', 'message' => $failed === 1 ? '1 stampa non riuscita' : "{$failed} stampe non riuscite"];
        }

        // Handed over and still sitting there: the queue has stopped moving.
        if (($stuck = $this->totals('stuck')) > 0) {
            return ['level' => 'danger', 'message' => $stuck === 1 ? '1 stampa ferma' : "{$stuck} stampe ferme"];
        }

        // Waiting to be retried on their own: worth saying, not worth alarming.
        if (($waiting = $this->totals('waiting')) > 0) {
            return ['level' => 'warning', 'message' => "{$waiting} in attesa"];
        }

        if ($this->monitoringStopped()) {
            return ['level' => 'warning', 'message' => 'monitoraggio fermo'];
        }

        return null;
    }

    /**
     * Whether nobody has looked at the printers for a while: the schedule that
     * releases held prints and recovers lost ones has stopped. Prints may well
     * still be coming out, which is exactly why this needs saying out loud.
     */
    #[Computed]
    public function monitoringStopped(): bool
    {
        $last = Cache::get(PollPrinterHealth::HEARTBEAT_KEY);

        return $last === null || Carbon::parse($last)->lt(now()->subSeconds(self::MONITORING_STALE_SECONDS));
    }

    /**
     * Documents not coming out, counted per printer and split by what is wrong
     * with them. One computed property, so a poll costs three counts however
     * many times the badge and its dialog ask.
     *
     * @return array<string, Collection<int, int>>
     */
    #[Computed]
    public function pending(): array
    {
        $printers = $this->relevantPrinters();

        return [
            'waiting' => $this->countPerPrinter($printers, [PrintJobStatus::Held]),
            'failed' => $this->countPerPrinter($printers, [PrintJobStatus::Failed], failedSince: now()->subMinutes(self::FAILED_FOR_MINUTES)),
            'stuck' => $this->countPerPrinter($printers, [PrintJobStatus::Pending, PrintJobStatus::Sending], queuedBefore: now()->subSeconds(self::STUCK_AFTER_SECONDS)),
        ];
    }

    protected function totals(string $kind): int
    {
        return (int) $this->pending[$kind]->sum();
    }

    /**
     * @param  Collection<int, Printer>  $printers
     * @param  array<int, PrintJobStatus>  $statuses
     * @return Collection<int, int>
     */
    protected function countPerPrinter(Collection $printers, array $statuses, ?Carbon $queuedBefore = null, ?Carbon $failedSince = null): Collection
    {
        if ($printers->isEmpty()) {
            return collect();
        }

        return PrintJob::query()
            ->whereIn('printer_id', $printers->pluck('id'))
            ->whereIn('status', $statuses)
            ->when($queuedBefore !== null, fn ($query) => $query->where('queued_at', '<', $queuedBefore))
            ->when($failedSince !== null, fn ($query) => $query->where('updated_at', '>=', $failedSince))
            ->selectRaw('printer_id, count(*) as total')
            ->groupBy('printer_id')
            ->pluck('total', 'printer_id')
            ->map(fn ($total): int => (int) $total);
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
     * Every relevant printer that is either unable to print or sitting on
     * documents that are not coming out, in the same order as the badge. The
     * full picture the badge has no room for, shown when the cashier taps it.
     *
     * @return array<int, array{name: string, status: string, blocked: bool, waiting: int, failed: int, stuck: int}>
     */
    #[Computed]
    public function issues(): array
    {
        $pending = $this->pending;

        return $this->relevantPrinters()
            ->map(fn (Printer $printer): array => [
                'name' => $printer->name,
                'status' => $printer->status->getLabel(),
                'blocked' => ! $printer->status->canPrint(),
                'waiting' => $pending['waiting'][$printer->id] ?? 0,
                'failed' => $pending['failed'][$printer->id] ?? 0,
                'stuck' => $pending['stuck'][$printer->id] ?? 0,
            ])
            ->filter(fn (array $issue): bool => $issue['blocked'] || $issue['waiting'] > 0 || $issue['failed'] > 0 || $issue['stuck'] > 0)
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

                @if ($this->monitoringStopped)
                    {{-- Said first and said plainly: with the monitoring down,
                         everything below is the last thing we knew, not the
                         situation now, and nothing recovers by itself. --}}
                    <p class="mt-2 rounded-lg bg-amber-100 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                        Monitoraggio fermo: nessun controllo delle stampanti da oltre un minuto. Le stampe in attesa non ripartono da sole.
                    </p>
                @endif

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
                                    @if ($issue['failed'] > 0)
                                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $issue['failed'] }} non riuscite</span>
                                    @endif
                                    @if ($issue['stuck'] > 0)
                                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $issue['stuck'] }} ferme</span>
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

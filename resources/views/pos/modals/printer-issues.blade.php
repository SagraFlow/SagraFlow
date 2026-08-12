@if ($showPrinterIssues)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Stato stampanti</h2>

            @if ($this->printerIssues === [])
                {{-- The list refreshes while open: say so instead of yanking the
                     modal away from under the cashier when everything recovers. --}}
                <p class="mt-2 text-neutral-500">Nessun problema in corso.</p>
            @else
                <ul class="mt-4 space-y-2">
                    @foreach ($this->printerIssues as $issue)
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

            <button type="button" wire:click="closePrinterIssues" class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Chiudi
            </button>
        </div>
    </div>
@endif

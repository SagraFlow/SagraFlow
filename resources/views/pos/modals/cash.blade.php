@if ($showCashModal)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Pagamento in contanti</h2>

            <div class="mt-4 space-y-1 rounded-lg bg-neutral-100 p-4 dark:bg-neutral-800">
                <div class="flex justify-between text-lg"><span>Totale</span><span class="font-semibold tabular-nums">{{ $this->money($this->orderTotal) }}</span></div>
                <div class="flex justify-between text-neutral-500"><span>Consegnato</span><span class="tabular-nums">{{ $this->money($this->cashReceivedCents) }}</span></div>
                <div class="mt-1 flex justify-between border-t border-neutral-300 pt-1 text-lg font-semibold dark:border-neutral-700">
                    <span>Resto</span>
                    <span @class(['tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $this->changeAmount >= 0, 'text-red-600' => $this->changeAmount < 0])>
                        {{ $this->changeAmount >= 0 ? $this->money($this->changeAmount) : 'insufficiente' }}
                    </span>
                </div>
            </div>

            <div class="mt-4 space-y-2">
                <button type="button" wire:click="setExactCash" class="w-full rounded-lg border border-neutral-900 py-3 font-medium dark:border-neutral-100">Esatto</button>
                <div class="grid grid-cols-4 gap-2">
                    @foreach ([['c' => 50, 'l' => '0,50'], ['c' => 100, 'l' => '1'], ['c' => 200, 'l' => '2'], ['c' => 500, 'l' => '5'], ['c' => 1000, 'l' => '10'], ['c' => 2000, 'l' => '20'], ['c' => 5000, 'l' => '50'], ['c' => 10000, 'l' => '100']] as $d)
                        <button type="button" wire:click="addCash({{ $d['c'] }})" class="rounded-lg border border-neutral-300 py-3 text-sm font-medium dark:border-neutral-700">+{{ $d['l'] }} €</button>
                    @endforeach
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <input type="text" inputmode="decimal" wire:model.live="cashInput"
                    class="h-12 w-full flex-1 rounded-lg border border-neutral-300 px-3 text-center text-lg tabular-nums dark:border-neutral-700 dark:bg-neutral-800">
                <button type="button" wire:click="resetCash" title="Azzera importo" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-neutral-300 text-red-600 dark:border-neutral-700">
                    <x-heroicon-o-trash class="h-5 w-5" />
                </button>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="closeCash" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="confirmCash" wire:loading.attr="disabled" wire:target="confirmCash" @disabled($this->changeAmount < 0)
                    class="flex-1 rounded-lg bg-neutral-900 py-3 font-medium text-white disabled:opacity-40 dark:bg-neutral-100 dark:text-neutral-900">Conferma</button>
            </div>
        </div>
    </div>
@endif

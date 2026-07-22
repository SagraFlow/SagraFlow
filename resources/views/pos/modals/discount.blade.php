@if ($showDiscount)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Sconto</h2>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="$set('discountType', null)" @class(['flex-1 rounded-lg border px-2 py-2.5 text-sm', 'border-neutral-900 font-medium dark:border-neutral-100' => $discountType === null, 'border-neutral-300 dark:border-neutral-700' => $discountType !== null])>Nessuno</button>
                <button type="button" wire:click="$set('discountType', 'fixed')" @class(['flex-1 rounded-lg border px-2 py-2.5 text-sm', 'border-neutral-900 font-medium dark:border-neutral-100' => $discountType === 'fixed', 'border-neutral-300 dark:border-neutral-700' => $discountType !== 'fixed'])>Importo €</button>
                <button type="button" wire:click="$set('discountType', 'percentage')" @class(['flex-1 rounded-lg border px-2 py-2.5 text-sm', 'border-neutral-900 font-medium dark:border-neutral-100' => $discountType === 'percentage', 'border-neutral-300 dark:border-neutral-700' => $discountType !== 'percentage'])>Percentuale</button>
            </div>

            @if ($discountType !== null)
                <input type="text" inputmode="decimal" wire:model.live="discountValue"
                    placeholder="{{ $discountType === 'percentage' ? 'es. 10 (%)' : 'es. 2,00 (€)' }}"
                    class="mt-3 w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
            @endif

            <div class="mt-4 rounded-lg bg-neutral-100 p-3 text-sm dark:bg-neutral-800">
                <div class="flex justify-between"><span>Subtotale</span><span class="tabular-nums">{{ $this->money($this->cartTotal) }}</span></div>
                <div class="flex justify-between text-neutral-500"><span>Sconto</span><span class="tabular-nums">- {{ $this->money($this->discountAmount) }}</span></div>
                <div class="mt-1 flex justify-between border-t border-neutral-300 pt-1 text-base font-semibold dark:border-neutral-700"><span>Totale</span><span class="tabular-nums">{{ $this->money($this->orderTotal) }}</span></div>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="cancelDiscount" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="applyDiscount" class="flex-1 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">Applica</button>
            </div>
        </div>
    </div>
@endif

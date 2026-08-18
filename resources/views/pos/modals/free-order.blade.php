@if ($showFreeOrder)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        {{-- A confirmation and not a payment screen: there is nothing to take,
             and the one thing worth a second of attention is that an order is
             about to leave the till without any money against it. --}}
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Ordine senza incasso</h2>
            <p class="mt-2 text-neutral-500">Lo sconto copre l'intero importo: non c'è niente da incassare e il cassetto resta chiuso.</p>

            <div class="mt-4 space-y-1 rounded-lg bg-neutral-100 p-4 dark:bg-neutral-800">
                <div class="flex justify-between text-neutral-500"><span>Subtotale</span><span class="tabular-nums">{{ $this->money($this->cartTotal + $this->coverTotal) }}</span></div>
                <div class="flex justify-between text-neutral-500"><span>Sconto</span><span class="tabular-nums">- {{ $this->money($this->discountAmount) }}</span></div>
                <div class="mt-1 flex justify-between border-t border-neutral-300 pt-1 text-lg font-semibold dark:border-neutral-700">
                    <span>Da incassare</span>
                    <span class="tabular-nums">{{ $this->money(0) }}</span>
                </div>
            </div>

            @error('checkout')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="closeFreeOrder" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="confirmFreeOrder" wire:loading.attr="disabled" wire:target="confirmFreeOrder"
                    class="flex-1 rounded-lg bg-neutral-900 py-3 font-medium text-white disabled:opacity-40 dark:bg-neutral-100 dark:text-neutral-900">Conferma</button>
            </div>
        </div>
    </div>
@endif

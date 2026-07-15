@if ($showCardModal)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Pagamento con carta</h2>
            <p class="mt-1 text-neutral-500">Importo <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->money($this->orderTotal) }}</span> da incassare sul POS.</p>
            <p class="mt-4 text-lg">La transazione è andata a buon fine?</p>

            @error('checkout')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <div class="mt-6 space-y-2">
                <button type="button" wire:click="confirmCard"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 py-3 font-medium text-white">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                    Pagamento riuscito
                </button>
                <button type="button" wire:click="cardToCash"
                    class="flex w-full items-center justify-center gap-2 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                    <x-heroicon-o-banknotes class="h-5 w-5" />
                    Paga in contanti
                </button>
                <button type="button" wire:click="closeCard"
                    class="w-full rounded-lg py-3 font-medium text-red-600">
                    Annulla
                </button>
            </div>
        </div>
    </div>
@endif

@if ($showClearCart)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Svuota carrello</h2>
            <p class="mt-2 text-neutral-500">Vuoi svuotare il carrello e azzerare l'ordine in corso?</p>
            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="cancelClearCart" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="clearCart" class="flex-1 rounded-lg bg-red-600 py-3 font-medium text-white">Svuota</button>
            </div>
        </div>
    </div>
@endif

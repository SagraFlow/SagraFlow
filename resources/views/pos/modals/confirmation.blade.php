@if ($placedOrderNumber !== null)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-8 text-center shadow-xl dark:bg-neutral-900">
            <p class="text-neutral-500">Ordine registrato</p>
            <p class="my-2 text-5xl font-bold tabular-nums">#{{ $placedOrderNumber }}</p>
            <button type="button" wire:click="newOrder" class="mt-4 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Nuovo ordine
            </button>
        </div>
    </div>
@endif

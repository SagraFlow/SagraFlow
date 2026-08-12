@if ($configuringBoard && $editingSlot !== null && ! $showKeyPicker)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">{{ $this->board[$editingSlot]['food']->name ?? 'Casella' }}</h2>
            <p class="mt-1 text-neutral-500">Cosa vuoi fare con questo tasto?</p>

            <div class="mt-6 space-y-3">
                <button type="button" wire:click="openKeyPicker" class="w-full rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                    Sostituisci
                </button>
                {{-- Moving is two taps, never a drag: dragging on a tablet starts by
                     accident and lands imprecisely. --}}
                <button type="button" wire:click="startMove" class="w-full rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                    Sposta
                </button>
                <button type="button" wire:click="removeKey" class="w-full rounded-lg border border-red-300 py-3 font-medium text-red-600 dark:border-red-900 dark:text-red-400">
                    Rimuovi
                </button>
            </div>

            <button type="button" wire:click="cancelCellActions" class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Annulla
            </button>
        </div>
    </div>
@endif

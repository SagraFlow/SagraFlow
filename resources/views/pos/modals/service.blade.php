@if ($showService)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        {{-- Keypad instead of a text field: on a tablet the number is pressed
             with a thumb, and the on-screen keyboard never covers half the
             screen. The pickup is not a table, so it gets a key of its own,
             below the digits and away from them. --}}
        <div class="flex max-h-[92vh] w-full max-w-sm flex-col overflow-y-auto overscroll-contain rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Tavolo o ritiro</h2>

            <div class="mt-4 flex h-16 items-center justify-center rounded-lg bg-neutral-100 text-3xl font-semibold tabular-nums dark:bg-neutral-800">
                {{ $tableInput === '' ? '-' : $tableInput }}
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                    <button type="button" wire:click="pressTableDigit({{ $digit }})"
                        class="h-14 rounded-lg border border-neutral-300 text-xl font-semibold tabular-nums dark:border-neutral-700">{{ $digit }}</button>
                @endforeach
                <button type="button" wire:click="clearTable" title="Azzera"
                    class="h-14 rounded-lg border border-neutral-300 text-base font-medium text-red-600 dark:border-neutral-700">C</button>
                <button type="button" wire:click="pressTableDigit(0)"
                    class="h-14 rounded-lg border border-neutral-300 text-xl font-semibold tabular-nums dark:border-neutral-700">0</button>
                <button type="button" wire:click="backspaceTable" title="Cancella cifra"
                    class="flex h-14 items-center justify-center rounded-lg border border-neutral-300 dark:border-neutral-700">
                    <x-heroicon-o-backspace class="h-6 w-6" />
                </button>
            </div>

            <button type="button" wire:click="chooseTable" @disabled($tableInput === '')
                class="mt-3 h-14 w-full rounded-lg bg-neutral-900 text-lg font-semibold text-white disabled:opacity-40 dark:bg-neutral-100 dark:text-neutral-900">
                Conferma tavolo
            </button>

            <button type="button" wire:click="choosePickup"
                class="mt-5 h-20 w-full rounded-xl border-2 border-neutral-900 text-2xl font-semibold dark:border-neutral-100">
                Ritiro
            </button>

            <button type="button" wire:click="closeService"
                class="mt-5 h-11 w-full rounded-lg border border-neutral-300 font-medium dark:border-neutral-700">Annulla</button>
        </div>
    </div>
@endif

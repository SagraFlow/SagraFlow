@if ($showService)
    {{-- The pad is pressed in the browser: building "104" used to cost three
         renders of the whole till for three characters. Only the confirmed
         number crosses to the server, which checks it before taking it - the
         rules here are for the finger, not for trust. --}}
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" wire:key="service-modal"
        x-data="{
            input: @js($tableInput),
            press(digit) {
                // A hall can number a table 0, so it is typed like any other
                // number - but only as the whole number: the next digit takes
                // its place rather than building an 04.
                if (this.input === '0') { this.input = String(digit); return }
                if (this.input.length >= {{ $this::TABLE_DIGITS }}) { return }
                this.input += digit
            },
        }">
        {{-- Keypad instead of a text field: on a tablet the number is pressed
             with a thumb, and the on-screen keyboard never covers half the
             screen. The pickup is not a table, so it gets a key of its own,
             below the digits and away from them. --}}
        <div class="flex max-h-[92vh] w-full max-w-sm flex-col overflow-y-auto overscroll-contain rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Tavolo o ritiro</h2>

            <div class="mt-4 flex h-16 items-center justify-center rounded-lg bg-neutral-100 text-3xl font-semibold tabular-nums dark:bg-neutral-800"
                x-text="input === '' ? '-' : input">
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                    <button type="button" x-on:click="press({{ $digit }})"
                        class="h-14 rounded-lg border border-neutral-300 text-xl font-semibold tabular-nums dark:border-neutral-700">{{ $digit }}</button>
                @endforeach
                <button type="button" x-on:click="input = ''" title="Azzera"
                    class="h-14 rounded-lg border border-neutral-300 text-base font-medium text-red-600 dark:border-neutral-700">C</button>
                <button type="button" x-on:click="press(0)"
                    class="h-14 rounded-lg border border-neutral-300 text-xl font-semibold tabular-nums dark:border-neutral-700">0</button>
                <button type="button" x-on:click="input = input.slice(0, -1)" title="Cancella cifra"
                    class="flex h-14 items-center justify-center rounded-lg border border-neutral-300 dark:border-neutral-700">
                    <x-heroicon-o-backspace class="h-6 w-6" />
                </button>
            </div>

            <button type="button" x-on:click="$wire.chooseTable(input)" x-bind:disabled="input === ''"
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

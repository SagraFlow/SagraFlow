@if ($showCovers)
    {{-- Pressed in the browser like the table's, for the same reason: only the
         confirmed number crosses to the server, which checks it before taking
         it. --}}
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" wire:key="covers-modal"
        x-data="{
            input: @js($coversInput),
            press(digit) {
                // Zero is a real answer to how many, so it is typed like any
                // other number - but only as the whole answer: the next digit
                // takes its place rather than building an 04.
                if (this.input === '0') { this.input = String(digit); return }
                if (this.input.length >= {{ $this::COVERS_DIGITS }}) { return }
                this.input += digit
            },
        }">
        {{-- Built like the table keypad, and for the same reason: pressed with a
             thumb, with no system keyboard climbing over the dialog. No key for
             "no covers" though: the question is how many, and zero is one of the
             answers, so it is on the pad with the others. --}}
        <div class="flex max-h-[92vh] w-full max-w-sm flex-col overflow-y-auto overscroll-contain rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Coperti</h2>

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

            <button type="button" x-on:click="$wire.chooseCovers(input)" x-bind:disabled="input === ''"
                class="mt-3 h-14 w-full rounded-lg bg-neutral-900 text-lg font-semibold text-white disabled:opacity-40 dark:bg-neutral-100 dark:text-neutral-900">
                Conferma coperti
            </button>

            <button type="button" wire:click="closeCovers"
                class="mt-5 h-11 w-full rounded-lg border border-neutral-300 font-medium dark:border-neutral-700">Annulla</button>
        </div>
    </div>
@endif

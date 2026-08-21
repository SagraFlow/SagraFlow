@if ($this->removingLine)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Rimuovere la riga?</h2>
            <p class="mt-2 text-neutral-500">
                {{ $this->removingLine['name'] }}
                @if ($this->lineNotes($this->removingLine) !== '')
                    <span class="text-amber-600 dark:text-amber-500">({{ $this->lineNotes($this->removingLine) }})</span>
                @endif
                @if (! empty($this->removingLine['note']))
                    <span class="italic">"{{ $this->removingLine['note'] }}"</span>
                @endif
            </p>
            {{-- Remove on the left, against the habit of the other dialogs: the
                 press that opens this one comes from the stepper on the right, and
                 a hand still moving must not find the destructive key under it. --}}
            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="confirmRemoveLine" class="flex-1 rounded-lg bg-red-600 py-3 font-medium text-white">Rimuovi</button>
                <button type="button" wire:click="cancelRemoveLine" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
            </div>
        </div>
    </div>
@endif

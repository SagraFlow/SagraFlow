<div class="flex flex-1 flex-col items-center justify-center gap-6 p-8">
    <h1 class="text-2xl font-semibold">Seleziona la cassa</h1>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        @foreach ($this->registers as $register)
            <button
                type="button"
                wire:click="selectRegister({{ $register->id }})"
                class="rounded-xl border border-neutral-300 bg-white px-8 py-6 text-lg font-medium shadow-sm transition dark:border-neutral-700 dark:bg-neutral-900"
            >
                {{ $register->name }}
            </button>
        @endforeach
        @if ($this->registers->isEmpty())
            <p class="col-span-full text-neutral-500">Nessuna cassa attiva configurata.</p>
        @endif
    </div>
</div>

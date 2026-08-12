<div class="flex flex-1 items-center justify-center p-8 text-center">
    <div>
        <h1 class="text-2xl font-semibold">Nessuna giornata aperta</h1>
        <p class="mt-2 text-neutral-500">Apri una giornata operativa dal pannello per iniziare a battere ordini.</p>

        {{-- The station is already chosen but nothing has started: this is the
             one screen where the setup left to do is still reachable. --}}
        <p class="mt-6 text-sm text-neutral-500">
            Cassa: <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ $this->cashRegister->name }}</span>
        </p>

        <div class="mt-3 flex flex-wrap justify-center gap-3">
            <button type="button" wire:click="changeRegister"
                class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-5 py-3 font-medium dark:border-neutral-700">
                <x-heroicon-o-building-storefront class="h-5 w-5" />
                Cambia cassa
            </button>
            <button type="button" wire:click="enterBoardConfig"
                class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-5 py-3 font-medium dark:border-neutral-700">
                <x-heroicon-o-squares-2x2 class="h-5 w-5" />
                Modifica schede
            </button>
        </div>
    </div>
</div>

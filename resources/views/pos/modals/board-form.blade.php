@if ($showBoardForm)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">{{ $creatingBoard ? 'Nuova scheda' : 'Modifica scheda' }}</h2>

            <label class="mt-4 block">
                <span class="mb-1 block text-sm text-neutral-500">Nome</span>
                <input type="text" wire:model="boardName" maxlength="100" placeholder="es. Griglia"
                    class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
            </label>
            @error('boardName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <label class="mt-4 block">
                <span class="mb-1 block text-sm text-neutral-500">Descrizione</span>
                <input type="text" wire:model="boardDescription" maxlength="150" placeholder="es. solo per la cassa del bar"
                    class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
                <span class="mt-1 block text-sm text-neutral-500">Serve a te per distinguere schede con lo stesso nome. Il cassiere non la vede.</span>
            </label>
            @error('boardDescription') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4 grid grid-cols-2 gap-3">
                <label class="block">
                    <span class="mb-1 block text-sm text-neutral-500">Colonne</span>
                    <input type="number" wire:model="boardColumns" min="1" max="12"
                        class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm text-neutral-500">Righe</span>
                    <input type="number" wire:model="boardRows" min="1" max="12"
                        class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
                </label>
            </div>
            @error('boardColumns') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('boardRows') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <p class="mt-3 text-sm text-neutral-500">I tasti si adattano allo schermo: la stessa scheda resta uguale su ogni tablet, solo più grande o più piccola.</p>

            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="closeBoardForm" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="saveBoard" class="flex-1 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">Salva</button>
            </div>

            @unless ($creatingBoard)
                <button type="button" wire:click="openDeleteBoard"
                    class="mt-3 w-full rounded-lg py-3 text-sm font-medium text-red-600 dark:text-red-400">
                    Elimina scheda
                </button>
            @endunless
        </div>
    </div>
@endif

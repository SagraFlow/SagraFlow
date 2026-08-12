@if ($showDeleteBoard && $this->selectedTab !== null)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Elimina scheda</h2>
            <p class="mt-2 text-neutral-500">Vuoi eliminare la scheda «{{ $this->selectedTab->name }}»? I tasti che hai disposto andranno persi.</p>
            <p class="mt-2 text-sm text-neutral-500">Le pietanze restano al loro posto: si vendono comunque dalla scheda «Tutto».</p>
            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="cancelDeleteBoard" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="deleteBoard" class="flex-1 rounded-lg bg-red-600 py-3 font-medium text-white">Elimina</button>
            </div>
        </div>
    </div>
@endif

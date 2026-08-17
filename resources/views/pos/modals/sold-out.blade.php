@if ($showSoldOut)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        {{-- A big order can run short on a dozen ingredients at once: the list
             scrolls so "Modifica ordine" never ends up below the screen. --}}
        <div class="flex max-h-[85vh] w-full max-w-sm flex-col rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="shrink-0 text-xl font-semibold text-red-600 dark:text-red-400">Ingredienti esauriti</h2>
            <p class="mt-2 shrink-0 text-neutral-500">Le scorte non bastano per completare l'ordine. Modifica il carrello e riprova.</p>
            <ul class="mt-4 min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain">
                @foreach ($soldOutItems as $item)
                    <li class="flex items-center justify-between rounded-lg bg-red-50 px-3 py-2 text-sm dark:bg-red-950/40">
                        <span class="font-medium">{{ $item['name'] }}</span>
                        <span class="text-red-600 dark:text-red-400">mancano {{ $item['missing'] }}</span>
                    </li>
                @endforeach
            </ul>
            <button type="button" wire:click="closeSoldOut" class="mt-6 w-full shrink-0 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Modifica ordine
            </button>
        </div>
    </div>
@endif

@if ($showSoldOut)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold text-red-600 dark:text-red-400">Ingredienti esauriti</h2>
            <p class="mt-2 text-neutral-500">Le scorte non bastano per completare l'ordine. Modifica il carrello e riprova.</p>
            <ul class="mt-4 space-y-1">
                @foreach ($soldOutItems as $item)
                    <li class="flex items-center justify-between rounded-lg bg-red-50 px-3 py-2 text-sm dark:bg-red-950/40">
                        <span class="font-medium">{{ $item['name'] }}</span>
                        <span class="text-red-600 dark:text-red-400">mancano {{ $item['missing'] }}</span>
                    </li>
                @endforeach
            </ul>
            <button type="button" wire:click="closeSoldOut" class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Modifica ordine
            </button>
        </div>
    </div>
@endif

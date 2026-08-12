@if ($showKeyPicker)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="flex max-h-[80vh] w-full max-w-md flex-col rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Scegli la pietanza</h2>

            <input
                type="search"
                wire:model.live.debounce.300ms="keySearch"
                placeholder="Cerca..."
                class="mt-4 w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800"
            >

            <div class="mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto">
                @forelse ($this->placeableFoods as $option)
                    <button
                        type="button"
                        wire:click="placeKey({{ $option['food']->id }})"
                        @disabled($option['placed'])
                        @class([
                            'flex w-full items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-3 text-left dark:border-neutral-800',
                            'opacity-40' => $option['placed'],
                        ])
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ $option['food']->name }}</span>
                            <span class="block truncate text-sm text-neutral-500">{{ $option['food']->category?->name }}</span>
                        </span>
                        @if ($option['placed'])
                            <span class="shrink-0 text-sm text-neutral-500">già su questa scheda</span>
                        @elseif ($option['orphan'])
                            {{-- The one thing that can silently go wrong: a food on
                                 no board at all, which a cashier working on boards
                                 would never see. Flagged where it gets fixed. --}}
                            <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-sm font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400">mai usata</span>
                        @endif
                    </button>
                @empty
                    <p class="py-6 text-center text-neutral-400">Nessuna pietanza trovata.</p>
                @endforelse
            </div>

            <button type="button" wire:click="closeKeyPicker" class="mt-6 w-full rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                Annulla
            </button>
        </div>
    </div>
@endif

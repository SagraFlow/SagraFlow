@if ($showStationBoards && $this->cashRegister !== null)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="flex max-h-[80vh] w-full max-w-md flex-col rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">Schede di «{{ $this->cashRegister->name }}»</h2>
            <p class="mt-2 text-neutral-500">Quali schede vede questa postazione e in che ordine. <span class="font-medium text-neutral-700 dark:text-neutral-300">Si apre sulla prima che mostra.</span></p>

            <div class="mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto">
                @foreach ($this->stationLayout as $index => $entry)
                    @php($tab = $entry['tab'])
                    <div wire:key="station-{{ $tab?->id ?? 'all' }}" class="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-800">
                        <div class="flex shrink-0 flex-col">
                            <button type="button" wire:click="moveBoardHereUp({{ $tab?->id ?? '' }})" @disabled($index === 0)
                                class="flex h-7 w-7 items-center justify-center rounded text-neutral-500 disabled:opacity-20">
                                <x-heroicon-m-chevron-up class="h-5 w-5" />
                            </button>
                            <button type="button" wire:click="moveBoardHereDown({{ $tab?->id ?? '' }})" @disabled($index === $this->stationLayout->count() - 1)
                                class="flex h-7 w-7 items-center justify-center rounded text-neutral-500 disabled:opacity-20">
                                <x-heroicon-m-chevron-down class="h-5 w-5" />
                            </button>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div @class(['truncate font-medium', 'text-neutral-400' => ! $entry['visible']])>{{ $tab?->name ?? 'Tutto' }}</div>
                            {{-- "Tutte le pietanze" describes the complete tab, not
                                 any board that happens to have no description. --}}
                            @if ($tab === null)
                                <div class="truncate text-sm text-neutral-500">Tutte le pietanze</div>
                            @elseif ($tab->description)
                                <div class="truncate text-sm text-neutral-500">{{ $tab->description }}</div>
                            @endif
                        </div>

                        @if ($tab !== null)
                            <button type="button" wire:click="toggleBoardHere({{ $tab->id }})"
                                @class([
                                    'w-24 shrink-0 rounded-md border px-2 py-1 text-center text-sm font-medium',
                                    'border-transparent bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' => $entry['visible'],
                                    'border-neutral-300 text-neutral-500 dark:border-neutral-700' => ! $entry['visible'],
                                ])>
                                {{ $entry['visible'] ? 'Visibile' : 'Nascosta' }}
                            </button>
                        @else
                            {{-- The complete tab is the safety net: it can be moved
                                 out of the way, never switched off. No button says
                                 so more clearly than any label could. --}}
                            <span class="w-24 shrink-0"></span>
                        @endif
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="closeStationBoards" class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Chiudi
            </button>
        </div>
    </div>
@endif

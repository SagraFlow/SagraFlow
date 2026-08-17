{{-- Keeps the menu current on its own beat: a key greys out by itself when
     another till sells the last portion. Slower than the printer badge on
     purpose, since selling a sold-out food is refused server side anyway. --}}
<div class="flex flex-1 flex-col overflow-hidden" wire:poll.30s>
    {{-- Board switcher. Hidden entirely when nobody has laid out a board: a sagra
         that does not customise sees exactly the interface it saw before.

         Nothing else is ever added above the grid: anything that appears and
         disappears here shortens the board and moves the keys. --}}
    @if ($this->barEntries->count() > 1 || $configuringBoard)
        <nav class="flex gap-2 overflow-x-auto border-b border-neutral-200 bg-white px-4 py-2 dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($this->barEntries as $entry)
                @php($tab = $entry['tab'])
                <button
                    type="button"
                    wire:key="bar-{{ $tab?->id ?? 'all' }}"
                    wire:click="selectTab({{ $tab?->id ?? '' }})"
                    @class([
                        'shrink-0 rounded-lg px-5 py-2.5 text-base font-semibold transition',
                        'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' => $this->selectedTab?->id === $tab?->id,
                        'text-neutral-500' => $this->selectedTab?->id !== $tab?->id,
                    ])
                >
                    {{ $tab?->name ?? 'Tutto' }}
                </button>
            @endforeach

            @if ($configuringBoard)
                {{-- Boards are created where they will appear, at the end of the
                     bar, rather than from a button somewhere else. --}}
                <button type="button" wire:click="openBoardForm(true)" title="Nuova scheda"
                    class="ml-auto flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-dashed border-neutral-300 text-neutral-500 dark:border-neutral-700">
                    <x-heroicon-o-plus class="h-5 w-5" />
                </button>
            @endif
        </nav>
    @endif

    @if ($this->selectedTab === null)
        {{-- The complete tab: every food, by category. The one place where
             nothing can ever be missing, so it scrolls and reflows freely. --}}
        <nav class="flex gap-2 overflow-x-auto border-b border-neutral-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($this->menu as $group)
                <button
                    type="button"
                    onclick="document.getElementById('cat-{{ $group['category']->id }}').scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="shrink-0 rounded-full bg-neutral-200 px-5 py-2.5 text-base font-medium text-neutral-700 transition dark:bg-neutral-800 dark:text-neutral-300"
                >
                    {{ $group['category']->name }}
                </button>
            @endforeach
        </nav>

        {{-- overscroll-contain: at the end of the list the drag stops there
             instead of being handed to the page, which has nowhere to go and
             answers by bouncing the whole till - or, outside fullscreen, by
             pulling Safari's refresh down in the middle of an order. --}}
        <div class="flex-1 space-y-6 overflow-y-auto overscroll-contain scroll-smooth p-4">
            @forelse ($this->menu as $group)
                <section wire:key="cat-{{ $group['category']->id }}" id="cat-{{ $group['category']->id }}" class="scroll-mt-4">
                    <h2 class="mb-2 text-base font-semibold uppercase tracking-wide text-neutral-400">{{ $group['category']->name }}</h2>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5 2xl:grid-cols-6">
                        @foreach ($group['foods'] as $item)
                            {{-- Keyed by food: the grid thins out on its own when
                                 a dish leaves tonight's menu or sells out, and a
                                 key must never inherit the identity of the one
                                 that used to be in its place. --}}
                            <x-pos.food-key wire:key="food-{{ $item['food']->id }}" :food="$item['food']" :available="$item['available']" :price="$this->money($item['food']->price)" :portions-left="$item['portionsLeft']" class="h-28" />
                        @endforeach
                    </div>
                </section>
            @empty
                <p class="py-8 text-center text-neutral-400">Nessuna pietanza disponibile.</p>
            @endforelse
        </div>
    @else
        {{-- A board: fixed number of columns and rows, no scrolling. The cells
             stretch to fill the screen, so the same board is the same board on
             every tablet, only with bigger or smaller keys. --}}
        <div class="flex-1 overflow-hidden p-4">
            <div
                class="grid h-full gap-2"
                style="grid-template-columns: repeat({{ $this->selectedTab->columns }}, minmax(0, 1fr)); grid-template-rows: repeat({{ $this->selectedTab->rows }}, minmax(0, 1fr));"
            >
                @foreach ($this->board as $slot => $cell)
                    {{-- Keyed by slot, everywhere: a cell can turn from key to
                         empty and back (a dish leaves the menu, two keys swap
                         places), and the slot is what stays put. Position alone
                         would let Livewire keep the old contents in a cell that
                         has just changed hands. --}}
                    @if ($configuringBoard)
                        {{-- Laying out: every cell is a target, empty ones included.
                             They are outlined here and invisible during service. --}}
                        <button
                            type="button"
                            wire:key="cell-{{ $slot }}"
                            wire:click="tapCell({{ $slot }})"
                            @class([
                                'flex flex-col items-center justify-center gap-1 overflow-hidden rounded-lg p-2 text-center transition',
                                'border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900' => $cell !== null,
                                'border-2 border-dashed border-neutral-300 dark:border-neutral-700' => $cell === null,
                                'ring-2 ring-neutral-900 dark:ring-neutral-100' => $movingSlot === $slot,
                            ])
                        >
                            @if ($cell !== null)
                                <span class="line-clamp-3 text-xl font-semibold leading-tight text-balance">{{ $cell['food']->key_name }}</span>
                                <span class="text-sm text-neutral-500">{{ $this->money($cell['food']->price) }}</span>
                            @endif
                        </button>
                    @elseif ($cell === null)
                        {{-- Empty cell. Rendered, never skipped: skipping it would
                             let the grid pull the next keys up and quietly undo
                             the stable positions the board exists for. --}}
                        <div wire:key="cell-{{ $slot }}"></div>
                    @else
                        <x-pos.food-key wire:key="cell-{{ $slot }}" :food="$cell['food']" :available="$cell['available']" :price="$this->money($cell['food']->price)" :portions-left="$cell['portionsLeft']" class="h-full" />
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>

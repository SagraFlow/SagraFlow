{{-- Config controls live in the cart column, which is dead weight while laying
     out a board (an order in progress blocks config mode anyway). Putting them
     above the grid would shorten it, and the organiser would be sizing keys that
     come out taller in service: the board has to be laid out at its real size. --}}
<aside class="flex w-1/4 min-w-80 flex-col border-l border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
    <div class="flex items-center gap-2 border-b border-neutral-200 bg-neutral-900 px-4 py-3 text-white dark:border-neutral-800 dark:bg-neutral-100 dark:text-neutral-900">
        <x-heroicon-o-squares-2x2 class="h-5 w-5 shrink-0" />
        <span class="text-base font-semibold">Configurazione schede</span>
    </div>

    <div class="flex-1 space-y-5 overflow-y-auto p-4">
        @if ($movingSlot !== null)
            <div class="rounded-lg bg-neutral-100 p-3 dark:bg-neutral-800">
                <p class="font-medium">Tocca la casella di destinazione.</p>
                <p class="mt-1 text-sm text-neutral-500">Se è occupata, i due tasti si scambiano di posto.</p>
            </div>
            <button type="button" wire:click="cancelCellActions" class="w-full rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                Annulla spostamento
            </button>
        @else
            {{-- What the selected board is --}}
            @if ($this->selectedTab === null)
                <div>
                    <div class="text-lg font-semibold">Tutto</div>
                    <p class="mt-1 text-sm text-neutral-500">Questa scheda non si modifica: contiene sempre ogni pietanza, ed è la rete di sicurezza quando qualcosa non è su nessuna scheda.</p>
                    <p class="mt-2 text-sm text-neutral-500">Scegli una scheda dalla barra in alto per disporne i tasti, o creane una con il + in fondo alla barra.</p>
                </div>
            @else
                <div>
                    {{-- The button sits beside the whole block, not inside the
                         title's line: a 40px control in there stretches the line
                         box and opens a gap under the name. Pinned right, so its
                         place never depends on how long a board is called. --}}
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-lg font-semibold leading-tight">{{ $this->selectedTab->name }}</div>
                            @if ($this->selectedTab->description)
                                <div class="mt-0.5 truncate text-sm leading-tight text-neutral-500">{{ $this->selectedTab->description }}</div>
                            @endif
                            <div class="mt-0.5 text-sm leading-tight text-neutral-500">{{ $this->selectedTab->columns }} colonne x {{ $this->selectedTab->rows }} righe</div>
                        </div>
                        <button type="button" wire:click="openBoardForm" title="Nome, descrizione e dimensioni"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-neutral-300 text-neutral-600 transition dark:border-neutral-700 dark:text-neutral-300">
                            <x-heroicon-o-pencil-square class="h-5 w-5" />
                        </button>
                    </div>
                    <p class="mt-3 text-sm text-neutral-500">Tocca una casella vuota per metterci una pietanza, una piena per sostituirla, spostarla o rimuoverla.</p>
                </div>
            @endif
        @endif
    </div>

    <div class="space-y-3 border-t border-neutral-200 p-4 dark:border-neutral-800">
        <button type="button" wire:click="openStationBoards" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
            <x-heroicon-o-bars-3 class="h-5 w-5" />
            Schede di questa cassa
        </button>
        <button type="button" wire:click="exitBoardConfig" class="w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
            Fine
        </button>
    </div>
</aside>

<aside class="flex w-1/4 min-w-80 flex-col border-l border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
    {{-- Customer, table or pickup, covers --}}
    <div class="space-y-3 border-b border-neutral-200 p-4 dark:border-neutral-800">
        <div>
            <label class="mb-1 block text-sm text-neutral-500">Nome Cliente</label>
            <input type="text" wire:model="customerName" maxlength="255" placeholder="Mario Rossi"
                class="h-11 w-full rounded-lg border border-neutral-300 px-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
        </div>
        {{-- The stepper is sized by what it holds, not by a share of the row:
             two 44px buttons plus a box where three digits sit with air around
             them, the same on a 10" and on a wide screen. The service choice
             takes whatever is left. --}}
        <div class="flex gap-3">
            {{-- Table and pickup are one choice, and it has to be made: the
                 button leads to the keypad and stays marked until it is. --}}
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-sm text-neutral-500">Tavolo o ritiro</label>
                <button type="button" wire:click="openService"
                    @class([
                        'flex h-11 w-full items-center justify-between gap-2 rounded-lg border px-3 text-base font-medium',
                        'border-neutral-300 dark:border-neutral-700' => $serviceType !== null,
                        'border-amber-500 text-amber-700 dark:border-amber-500/70 dark:text-amber-400' => $serviceType === null,
                    ])>
                    <span class="truncate">{{ $this->serviceLabel }}</span>
                    <x-heroicon-m-pencil-square class="h-5 w-5 shrink-0 text-neutral-400" />
                </button>
            </div>
            <div class="w-36 shrink-0">
                <label class="mb-1 block text-sm text-neutral-500">Coperti</label>
                <div class="flex h-11 items-stretch overflow-hidden rounded-lg border border-neutral-300 dark:border-neutral-700">
                    <button type="button" wire:click="decCovers" class="flex w-11 shrink-0 items-center justify-center border-r border-neutral-300 dark:border-neutral-700"><x-heroicon-m-minus class="h-5 w-5" /></button>
                    <input type="number" min="0" max="999" wire:model.live="covers" wire:blur="normalizeCovers"
                        class="w-full min-w-0 border-0 bg-transparent px-1 text-center text-base tabular-nums focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                    <button type="button" wire:click="incCovers" class="flex w-11 shrink-0 items-center justify-center border-l border-neutral-300 dark:border-neutral-700"><x-heroicon-m-plus class="h-5 w-5" /></button>
                </div>
            </div>
        </div>
    </div>

    {{-- Cart lines --}}
    <div class="flex-1 space-y-2 overflow-y-auto overscroll-contain p-4">
        @if (! empty($cart))
            {{-- Negative margins pull the row's text back onto the same edges as
                 the cards below, which the button's own padding would break. --}}
            <div class="-mx-1 flex items-center justify-between pb-1">
                <span class="px-1 text-sm font-medium uppercase tracking-wide text-neutral-400">Carrello</span>
                <button type="button" wire:click="openClearCart"
                    class="inline-flex items-center gap-1 rounded-md px-1 py-1 text-sm font-medium text-red-600">
                    <x-heroicon-o-trash class="h-4 w-4" />
                    Svuota
                </button>
            </div>
        @endif
        @forelse ($cart as $key => $line)
            {{-- wire:key on the cart line: without it Livewire matches the rows
                 by position, so removing one from the middle leaves the note and
                 the quantity of the row below sitting on the wrong dish. The
                 cart key already identifies a line by food, choices and note,
                 which is exactly the identity the DOM needs.

                 Name, its deviations and the note read as one block across the
                 full width of the column: on a 10" tablet the buttons alongside
                 left the name a hundred pixels and it was cut mid-word, which is
                 the one thing on the line that must be read. The price and the
                 buttons drop to a row of their own underneath. --}}
            <div wire:key="line-{{ $key }}" class="rounded-lg border border-neutral-200 px-3 py-2.5 dark:border-neutral-800">
                <div class="space-y-0.5">
                    <div class="text-lg font-semibold leading-tight">{{ $line['name'] }}</div>
                    @if ($this->lineNotes($line) !== '')
                        <div class="text-sm leading-tight text-amber-600 dark:text-amber-500">{{ $this->lineNotes($line) }}</div>
                    @endif
                    @if (! empty($line['note']))
                        <div class="text-sm italic leading-tight text-neutral-500">"{{ $line['note'] }}"</div>
                    @endif
                </div>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <span class="text-base tabular-nums text-neutral-500">{{ $this->money($this->lineTotal($line)) }}</span>
                    {{-- Edit sits apart from the quantity stepper: one changes what
                         the line is, the others only how many. --}}
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="editLine('{{ $key }}')" title="Modifica" class="flex h-11 w-11 items-center justify-center rounded-md border border-neutral-300 text-neutral-600 transition dark:border-neutral-700 dark:text-neutral-300"><x-heroicon-o-pencil-square class="h-5 w-5" /></button>
                        <div class="flex items-center gap-1">
                            {{-- On the last portion the minus turns into a bin:
                                 the press that would empty the line says so
                                 before it is made, and it asks. No fourth target
                                 on a row that has no room for one. --}}
                            <button type="button" wire:click="decrementLine('{{ $key }}')"
                                @class([
                                    'flex h-11 w-11 items-center justify-center rounded-md bg-neutral-200 dark:bg-neutral-800',
                                    'text-red-600 dark:text-red-500' => $line['quantity'] <= 1,
                                ])>
                                @if ($line['quantity'] <= 1)
                                    <x-heroicon-m-trash class="h-5 w-5" />
                                @else
                                    <x-heroicon-m-minus class="h-5 w-5" />
                                @endif
                            </button>
                            <span class="w-8 text-center text-lg tabular-nums">{{ $line['quantity'] }}</span>
                            <button type="button" wire:click="incrementLine('{{ $key }}')" class="flex h-11 w-11 items-center justify-center rounded-md bg-neutral-200 dark:bg-neutral-800"><x-heroicon-m-plus class="h-5 w-5" /></button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <p class="py-8 text-center text-sm text-neutral-400">Carrello vuoto</p>
        @endforelse
    </div>

    {{-- Totals + payment --}}
    <div class="space-y-4 border-t border-neutral-200 p-4 dark:border-neutral-800">
        <div class="text-sm">
            @if ($this->discountAmount > 0 || $this->coverTotal > 0)
                {{-- The breakdown lines belong together and lead into the total,
                     so they sit tight and the total gets the gap. --}}
                <div class="mb-3 space-y-1">
                    <div class="flex justify-between gap-3 text-neutral-500"><span class="truncate">Subtotale</span><span class="tabular-nums">{{ $this->money($this->cartTotal) }}</span></div>
                    @if ($this->coverTotal > 0)
                        <div class="flex justify-between gap-3 text-neutral-500"><span class="truncate">Coperti ({{ $covers }} x {{ $this->money($this->coverCharge) }})</span><span class="tabular-nums">{{ $this->money($this->coverTotal) }}</span></div>
                    @endif
                    @if ($this->discountAmount > 0)
                        <div class="flex justify-between gap-3 text-neutral-500"><span class="truncate">Sconto</span><span class="tabular-nums">- {{ $this->money($this->discountAmount) }}</span></div>
                    @endif
                </div>
            @endif
            <div class="flex items-center justify-between gap-3 text-lg font-semibold">
                <span>Totale</span>
                <div class="flex items-center gap-3">
                    <span class="tabular-nums">{{ $this->money($this->orderTotal) }}</span>
                    <button type="button" wire:click="openDiscount" title="Sconto"
                        class="flex h-11 w-11 items-center justify-center rounded-md border border-neutral-300 text-neutral-600 transition dark:border-neutral-700 dark:text-neutral-300">
                        <x-heroicon-o-pencil-square class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </div>

        @error('checkout')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        @if (! empty($cart) && $this->orderTotal === 0)
            {{-- Nothing to take: a discount has covered the lot. Two tender
                 buttons would both be a lie, and the cash one would kick the
                 drawer open for no money. --}}
            <button type="button" wire:click="startFreeOrder"
                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-neutral-900 py-3 font-medium text-white transition dark:bg-neutral-100 dark:text-neutral-900">
                <x-heroicon-o-gift class="h-5 w-5" />
                Conferma ordine
            </button>
        @else
        <div class="grid grid-cols-2 gap-2">
            <button type="button" wire:click="startCash" @disabled(empty($cart))
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-neutral-900 py-3 font-medium text-white transition disabled:opacity-40 dark:bg-neutral-100 dark:text-neutral-900">
                <x-heroicon-o-banknotes class="h-5 w-5" />
                Contanti
            </button>
            <button type="button" wire:click="startCard" @disabled(empty($cart))
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-neutral-300 bg-neutral-100 py-3 font-medium text-neutral-900 transition disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                <x-heroicon-o-credit-card class="h-5 w-5" />
                Carta
            </button>
        </div>
        @endif
    </div>
</aside>

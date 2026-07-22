<aside class="flex w-1/4 min-w-80 flex-col border-l border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
    {{-- Customer, table, covers --}}
    <div class="space-y-3 border-b border-neutral-200 p-4 dark:border-neutral-800">
        <div>
            <label class="mb-1 block text-sm text-neutral-500">Nome Cliente</label>
            <input type="text" wire:model="customerName" maxlength="255" placeholder="Mario Rossi"
                class="h-10 w-full rounded-lg border border-neutral-300 px-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
        </div>
        <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2 min-w-0">
                <label class="mb-1 block text-sm text-neutral-500">N. Tavolo</label>
                <input type="number" min="1" max="9999" wire:model.live="tableNumber" placeholder="12"
                    class="h-10 w-full rounded-lg border border-neutral-300 px-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
            </div>
            <div class="min-w-0">
                <label class="mb-1 block text-sm text-neutral-500">Coperti</label>
                <div class="flex h-10 items-stretch overflow-hidden rounded-lg border border-neutral-300 dark:border-neutral-700">
                    <button type="button" wire:click="decCovers" class="flex shrink-0 items-center justify-center border-r border-neutral-300 w-10 dark:border-neutral-700"><x-heroicon-m-minus class="h-5 w-5" /></button>
                    <input type="number" min="0" max="999" wire:model.live="covers"
                        class="w-full min-w-0 border-0 bg-transparent px-1 text-center text-base tabular-nums focus:outline-none focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                    <button type="button" wire:click="incCovers" class="flex shrink-0 items-center justify-center border-l border-neutral-300 w-10 dark:border-neutral-700"><x-heroicon-m-plus class="h-5 w-5" /></button>
                </div>
            </div>
        </div>
    </div>

    {{-- Cart lines --}}
    <div class="flex-1 space-y-2 overflow-y-auto p-4">
        @if (! empty($cart))
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-neutral-500">Carrello</span>
                <button type="button" wire:click="openClearCart"
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm font-medium text-red-600">
                    <x-heroicon-o-trash class="h-4 w-4" />
                    Svuota
                </button>
            </div>
        @endif
        @forelse ($cart as $key => $line)
            <div class="rounded-lg border border-neutral-200 p-2 dark:border-neutral-800">
                <div class="flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-base font-medium">{{ $line['name'] }}</div>
                        @if ($this->lineNotes($line) !== '')
                            <div class="truncate text-sm text-amber-600 dark:text-amber-500">{{ $this->lineNotes($line) }}</div>
                        @endif
                        @if (! empty($line['note']))
                            <div class="truncate text-sm italic text-neutral-500">“{{ $line['note'] }}”</div>
                        @endif
                        <div class="text-sm text-neutral-500">{{ $this->money($this->lineTotal($line)) }}</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="editLine('{{ $key }}')" title="Modifica" class="mr-1 flex h-10 w-10 items-center justify-center rounded-md border border-neutral-300 text-neutral-600 transition dark:border-neutral-700 dark:text-neutral-300"><x-heroicon-o-pencil-square class="h-5 w-5" /></button>
                        <button type="button" wire:click="decrementLine('{{ $key }}')" class="flex h-10 w-10 items-center justify-center rounded-md bg-neutral-200 dark:bg-neutral-800"><x-heroicon-m-minus class="h-5 w-5" /></button>
                        <span class="w-8 text-center text-lg tabular-nums">{{ $line['quantity'] }}</span>
                        <button type="button" wire:click="incrementLine('{{ $key }}')" class="flex h-10 w-10 items-center justify-center rounded-md bg-neutral-200 dark:bg-neutral-800"><x-heroicon-m-plus class="h-5 w-5" /></button>
                    </div>
                </div>
            </div>
        @empty
            <p class="py-8 text-center text-sm text-neutral-400">Carrello vuoto</p>
        @endforelse
    </div>

    {{-- Totals + payment --}}
    <div class="space-y-3 border-t border-neutral-200 p-4 dark:border-neutral-800">
        <div class="space-y-3 text-sm">
            @if ($this->discountAmount > 0 || $this->coverTotal > 0)
                <div class="space-y-1">
                    <div class="flex justify-between text-neutral-500"><span>Subtotale</span><span class="tabular-nums">{{ $this->money($this->cartTotal) }}</span></div>
                    @if ($this->coverTotal > 0)
                        <div class="flex justify-between text-neutral-500"><span>Coperti ({{ $covers }} × {{ $this->money($this->coverCharge) }})</span><span class="tabular-nums">{{ $this->money($this->coverTotal) }}</span></div>
                    @endif
                    @if ($this->discountAmount > 0)
                        <div class="flex justify-between text-neutral-500"><span>Sconto</span><span class="tabular-nums">− {{ $this->money($this->discountAmount) }}</span></div>
                    @endif
                </div>
            @endif
            <div class="flex items-center justify-between text-lg font-semibold">
                <span>Totale</span>
                <div class="flex items-center gap-2">
                    <span class="tabular-nums">{{ $this->money($this->orderTotal) }}</span>
                    <button type="button" wire:click="openDiscount" title="Sconto"
                        class="flex h-10 w-10 items-center justify-center rounded-md border border-neutral-300 text-neutral-600 transition dark:border-neutral-700 dark:text-neutral-300">
                        <x-heroicon-o-pencil-square class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </div>

        @error('checkout')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

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
    </div>
</aside>

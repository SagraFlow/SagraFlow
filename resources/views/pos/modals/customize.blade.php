@if ($this->customizingFood)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold">{{ $this->customizingFood->name }}</h2>

            @if ($this->customizableIngredients->isNotEmpty())
                <div class="mt-4 space-y-2">
                    @foreach ($this->customizableIngredients as $ingredient)
                        <div class="flex items-center justify-between rounded-lg border border-neutral-200 p-2 dark:border-neutral-800">
                            <div class="min-w-0">
                                <div class="text-base font-medium">{{ $ingredient->name }}</div>
                                @if ($ingredient->surcharge > 0)
                                    <div class="text-sm text-neutral-500">+{{ $this->money($ingredient->surcharge) }} a porzione extra</div>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="decIngredient({{ $ingredient->id }})" @disabled($customizeQty[$ingredient->id] <= $ingredient->pivot->min_quantity)
                                    class="flex h-11 w-11 items-center justify-center rounded-md bg-neutral-200 disabled:opacity-30 dark:bg-neutral-800"><x-heroicon-m-minus class="h-5 w-5" /></button>
                                <span class="w-8 text-center text-lg tabular-nums">{{ $customizeQty[$ingredient->id] }}</span>
                                <button type="button" wire:click="incIngredient({{ $ingredient->id }})" @disabled($customizeQty[$ingredient->id] >= $ingredient->pivot->max_quantity)
                                    class="flex h-11 w-11 items-center justify-center rounded-md bg-neutral-200 disabled:opacity-30 dark:bg-neutral-800"><x-heroicon-m-plus class="h-5 w-5" /></button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex items-center justify-between text-lg font-semibold">
                    <span>Prezzo</span>
                    <span class="tabular-nums">{{ $this->money($this->customizingFood->price + $this->customizeSurcharge) }}</span>
                </div>
            @endif

            <div class="mt-4">
                <label class="mb-1 block text-sm text-neutral-500">Note</label>
                <textarea wire:model="customizeNote" rows="2" maxlength="255" placeholder="es. senza glutine, ben cotto…"
                    class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800"></textarea>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="cancelCustomize" class="flex-1 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">Annulla</button>
                <button type="button" wire:click="confirmCustomize" class="flex-1 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">Salva</button>
            </div>
        </div>
    </div>
@endif

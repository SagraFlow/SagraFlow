@props(['food', 'available', 'price'])

{{-- One till key. Shared by the generated "Tutto" tab and the boards so a food
     looks and behaves the same wherever the cashier taps it. --}}
<button
    type="button"
    @if ($available)
        wire:click="addFood({{ $food->id }})"
    @else
        disabled
    @endif
    {{ $attributes->class([
        'flex flex-col justify-between overflow-hidden rounded-lg border border-neutral-200 bg-white p-2 text-left shadow-sm transition dark:border-neutral-800 dark:bg-neutral-900',
        'opacity-50 cursor-not-allowed' => ! $available,
    ]) }}
>
    <span class="text-base font-medium leading-tight">{{ $food->name }}</span>
    @if ($available)
        <span class="text-sm text-neutral-500">{{ $price }}</span>
    @else
        <span class="text-sm font-semibold uppercase text-red-600 dark:text-red-400">Esaurito</span>
    @endif
</button>

@props(['food', 'available', 'price', 'portionsLeft' => null])

{{-- One till key. Shared by the generated "Tutto" tab and the boards so a food
     looks and behaves the same wherever the cashier taps it.

     The pressed state lives here and on no other button of the till, this same
     key while a board is being laid out included: selling is the one case where
     the effect of the tap is invisible from where the finger is, landing in a
     cart on the far side of the screen once the server answers. Everywhere else
     something opens or moves on the spot. Pure CSS, so it shows on contact
     instead of after the round trip, which is what stops the second,
     duplicating tap; duration-75 because at the default 150ms a key tapped
     hundreds of times an evening reads as lagging. --}}
<button
    type="button"
    @if ($available)
        wire:click="addFood({{ $food->id }})"
    @else
        disabled
    @endif
    {{ $attributes->class([
        'flex flex-col items-center justify-center gap-1 overflow-hidden rounded-lg border border-neutral-200 bg-white p-2 text-center shadow-sm transition duration-75 active:scale-[0.97] active:bg-neutral-100 motion-reduce:active:scale-100 dark:border-neutral-800 dark:bg-neutral-900 dark:active:bg-neutral-800',
        'opacity-50 cursor-not-allowed' => ! $available,
    ]) }}
>
    {{-- The name carries the tap: large, centred, and the same size on every
         key of every screen, so the cashier reads shapes in fixed places
         rather than re-reading. Clamped rather than clipped, so a long name
         loses a line and never a word halfway through. Abbreviated here when
         the organiser gave it a short name, and nowhere else. --}}
    <span class="line-clamp-3 text-xl font-semibold leading-tight text-balance">{{ $food->key_name }}</span>
    @if ($available)
        {{-- Price and stock are the same size and sit tight together, one fact
             about the key under another: the room for the second line comes
             from their own leading, not from the name's. --}}
        <span class="flex flex-col items-center leading-tight">
            <span class="text-sm text-neutral-500">{{ $price }}</span>
            {{-- Only what the stock actually knows: a food made of untracked
                 ingredients shows nothing here rather than a made-up figure.
                 neutral-500 on light, not 400: the same grey that reads fine on
                 a desk is under 3:1 on a screen fighting a floodlight in a
                 marquee, and this is a figure people act on. --}}
            @if ($portionsLeft !== null)
                <span class="text-sm tabular-nums text-neutral-500 dark:text-neutral-400">{{ $portionsLeft }} {{ $portionsLeft === 1 ? 'porzione' : 'porzioni' }}</span>
            @endif
        </span>
    @else
        <span class="text-sm font-semibold uppercase text-red-600 dark:text-red-400">Esaurito</span>
    @endif
</button>

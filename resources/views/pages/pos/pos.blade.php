<div class="flex h-full flex-col">
    {{-- Which station this tablet is comes first, config mode included: boards
         are laid out for a station, so there is always one to lay them out for.
         The day, on the other hand, need not be open: boards are arranged before
         the sagra starts, which is also when a fresh tablet is set up. --}}
    @if (! $this->cashRegister)
        @include('pos.partials.register-picker')
    @elseif (! $this->day && ! $configuringBoard)
        @include('pos.partials.no-day')
    @else
        @include('pos.partials.header')

        <div class="flex flex-1 overflow-hidden">
            @include('pos.partials.menu')
            {{-- The right column swaps role in config mode, so the menu column
                 keeps exactly the same size in both: the board is laid out at
                 the size it will be sold from. --}}
            @if ($configuringBoard)
                @include('pos.partials.board-config')
            @else
                @include('pos.partials.cart')
            @endif
        </div>

        @include('pos.modals.customize')
        @include('pos.modals.service')
        @include('pos.modals.covers')
        @include('pos.modals.remove-line')
        @include('pos.modals.clear-cart')
        @include('pos.modals.station-boards')
        @include('pos.modals.board-form')
        @include('pos.modals.delete-board')
        @include('pos.modals.cell-actions')
        @include('pos.modals.key-picker')
        @include('pos.modals.sold-out')
        @include('pos.modals.reservation-expired')
        @include('pos.modals.cash')
        @include('pos.modals.free-order')
        @include('pos.modals.card')
        @include('pos.modals.discount')
        @include('pos.modals.confirmation')
    @endif
</div>

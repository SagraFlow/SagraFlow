<div class="flex h-full flex-col">
    @if (! $this->day)
        @include('pos.partials.no-day')
    @elseif (! $this->cashRegister)
        @include('pos.partials.register-picker')
    @else
        @include('pos.partials.header')

        <div class="flex flex-1 overflow-hidden">
            @include('pos.partials.menu')
            @include('pos.partials.cart')
        </div>

        @include('pos.modals.customize')
        @include('pos.modals.clear-cart')
        @include('pos.modals.cash')
        @include('pos.modals.card')
        @include('pos.modals.discount')
        @include('pos.modals.confirmation')
    @endif
</div>

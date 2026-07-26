{{-- Always polls so the banner appears/clears within seconds even with no cart activity. --}}
<div wire:poll.5s>
    @if ($alert = $this->printerAlert)
        <div @class([
            'flex items-center gap-2 px-6 py-2 text-sm font-medium text-white',
            'bg-red-600' => $alert['level'] === 'danger',
            'bg-amber-500' => $alert['level'] === 'warning',
        ])>
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
            <span>{{ $alert['message'] }}</span>
        </div>
    @endif
</div>

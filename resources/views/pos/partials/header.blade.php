<header class="flex items-center justify-between border-b border-neutral-200 bg-white px-6 py-3 dark:border-neutral-800 dark:bg-neutral-900">
    <div class="flex items-baseline gap-3">
        <span class="text-lg font-semibold">{{ $this->cashRegister->name }}</span>
        <span class="text-sm text-neutral-500">{{ $this->day->display_name }}</span>
    </div>
    <div class="flex items-center gap-1 text-neutral-600 dark:text-neutral-300">
        {{-- Cash drawer --}}
        <button type="button" wire:click="openCashDrawer" title="Apri cassetto"
            class="flex h-10 w-10 items-center justify-center rounded-md">
            <x-heroicon-o-inbox-arrow-down class="h-6 w-6" />
        </button>

        {{-- Theme toggle --}}
        <button type="button" title="Cambia tema"
            x-data="{ dark: document.documentElement.classList.contains('dark') }"
            x-on:click="dark = ! dark; document.documentElement.classList.toggle('dark', dark); localStorage.theme = dark ? 'dark' : 'light'"
            class="flex h-10 w-10 items-center justify-center rounded-md">
            <x-heroicon-o-sun class="h-6 w-6" x-show="dark" x-cloak />
            <x-heroicon-o-moon class="h-6 w-6" x-show="! dark" x-cloak />
        </button>

        {{-- Fullscreen toggle --}}
        <button type="button" title="Schermo intero"
            x-data="{ fs: false }"
            x-init="document.addEventListener('fullscreenchange', () => fs = !! document.fullscreenElement)"
            x-on:click="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()"
            class="flex h-10 w-10 items-center justify-center rounded-md">
            <x-heroicon-o-arrows-pointing-out class="h-6 w-6" x-show="! fs" x-cloak />
            <x-heroicon-o-arrows-pointing-in class="h-6 w-6" x-show="fs" x-cloak />
        </button>

        {{-- User menu --}}
        <div class="relative" x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open" title="{{ auth()->user()?->name }}"
                class="flex h-10 w-10 items-center justify-center rounded-md">
                <x-heroicon-o-user-circle class="h-7 w-7" />
            </button>
            <div x-show="open" x-on:click.outside="open = false" x-transition x-cloak
                class="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-neutral-200 px-4 py-3 text-sm dark:border-neutral-800">
                    <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ auth()->user()?->name }}</div>
                    <div class="text-neutral-500">{{ $this->cashRegister->name }}</div>
                </div>
                <button type="button" wire:click="changeRegister" class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm">
                    <x-heroicon-o-building-storefront class="h-5 w-5" />
                    Cambia postazione cassa
                </button>
                <button type="button" wire:click="logout" class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm text-red-600">
                    <x-heroicon-o-arrow-right-start-on-rectangle class="h-5 w-5" />
                    Logout
                </button>
            </div>
        </div>
    </div>
</header>

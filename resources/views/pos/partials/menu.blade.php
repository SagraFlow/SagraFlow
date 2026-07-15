<div class="flex flex-1 flex-col overflow-hidden">
    <nav class="flex gap-2 overflow-x-auto border-b border-neutral-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900">
        @foreach ($this->menu as $group)
            <button
                type="button"
                onclick="document.getElementById('cat-{{ $group['category']->id }}').scrollIntoView({ behavior: 'smooth', block: 'start' })"
                class="shrink-0 rounded-full bg-neutral-200 px-5 py-2.5 text-base font-medium text-neutral-700 transition dark:bg-neutral-800 dark:text-neutral-300"
            >
                {{ $group['category']->name }}
            </button>
        @endforeach
    </nav>

    <div class="flex-1 space-y-6 overflow-y-auto scroll-smooth p-4">
        @forelse ($this->menu as $group)
            <section id="cat-{{ $group['category']->id }}" class="scroll-mt-4">
                <h2 class="mb-2 text-base font-semibold uppercase tracking-wide text-neutral-400">{{ $group['category']->name }}</h2>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5 2xl:grid-cols-6">
                    @foreach ($group['foods'] as $food)
                        <button
                            type="button"
                            wire:click="addFood({{ $food->id }})"
                            class="flex h-20 flex-col justify-between rounded-lg border border-neutral-200 bg-white p-2 text-left shadow-sm transition dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <span class="text-base font-medium leading-tight">{{ $food->name }}</span>
                            <span class="text-sm text-neutral-500">{{ $this->money($food->price) }}</span>
                        </button>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="py-8 text-center text-neutral-400">Nessuna pietanza disponibile.</p>
        @endforelse
    </div>
</div>

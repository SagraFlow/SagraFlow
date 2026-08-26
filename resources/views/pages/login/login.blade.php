<div class="flex h-full items-center justify-center p-4">
    <form wire:submit="login" class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
        <h1 class="text-xl font-semibold">{{ config('app.name', 'SagraFlow') }}</h1>
        <p class="mt-1 text-neutral-500">Accedi per aprire la cassa.</p>

        <label class="mt-6 block">
            <span class="mb-1 block text-sm text-neutral-500">Mail</span>
            {{-- No autocorrect and no capitals: on a tablet keyboard both turn a
                 correct address into a rejected one. --}}
            <input type="email" wire:model="email" autocomplete="username" autocapitalize="none" autocorrect="off"
                inputmode="email" required autofocus
                class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
        </label>
        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

        <label class="mt-4 block">
            <span class="mb-1 block text-sm text-neutral-500">Password</span>
            <input type="password" wire:model="password" autocomplete="current-password" required
                class="w-full rounded-lg border border-neutral-300 px-3 py-3 text-base dark:border-neutral-700 dark:bg-neutral-800">
        </label>
        @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
            class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white disabled:opacity-40 dark:bg-neutral-100 dark:text-neutral-900">
            Accedi
        </button>
    </form>
</div>

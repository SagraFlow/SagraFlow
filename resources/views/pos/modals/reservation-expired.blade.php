@if ($showReservationExpired)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
            <h2 class="text-xl font-semibold text-amber-600 dark:text-amber-400">Pagamento scaduto</h2>
            <p class="mt-2 text-neutral-500">La schermata di pagamento è rimasta aperta troppo a lungo ed è stata annullata. L'ordine è ancora nel carrello: controlla la disponibilità e riprova.</p>
            <button type="button" wire:click="closeReservationExpired" class="mt-6 w-full rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                Torna al carrello
            </button>
        </div>
    </div>
@endif

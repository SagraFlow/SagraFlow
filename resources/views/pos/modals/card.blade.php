@if ($showCardModal)
    @php($attempt = $this->cardTransaction)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" wire:poll.60s="keepReservationAlive">
        {{-- Four situations, and they are told apart on purpose. Waiting is not
             a question to answer; a refusal is over and can be tried again;
             silence is the one that must be resolved with the terminal before
             anything else; and no terminal at all is the flow this till has
             always had, which stays available whatever the integration does. --}}
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900"
            @if ($this->cardPaymentPending())
                wire:poll.2s="pollCardPayment"
            @elseif ($terminalBusyWith !== null)
                wire:poll.3s="watchTerminal"
            @endif>
            <h2 class="text-xl font-semibold">Pagamento con carta</h2>
            <p class="mt-1 text-neutral-500">Importo <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->money($this->orderTotal) }}</span> da incassare sul POS.</p>

            @if ($unresolvedPayment !== null)
                {{-- The station's previous payment never got an answer. Sending
                     another amount now, before anyone has looked at the
                     terminal, is the most ordinary way to charge a customer
                     twice: it needs no failure, only a second tap. --}}
                <div class="mt-6 rounded-lg bg-amber-50 p-4 dark:bg-amber-950/40">
                    <div class="font-semibold text-amber-700 dark:text-amber-400">C'è un pagamento senza esito</div>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        {{ $unresolvedPayment['amount'] }} delle {{ $unresolvedPayment['at'] }} su questa cassa. Se è andato a buon fine, quel cliente ha già pagato: <strong>controlla il terminale</strong> prima di incassarne un altro.
                    </p>
                </div>
            @elseif ($terminalBusyWith !== null)
                {{-- Busy is not "no terminal": there is nothing here the cashier
                     could have seen, so she gets a way on rather than a
                     confirmation of something happening at another till. --}}
                @if ($terminalFreeAgain)
                    {{-- Free, but very likely still in the other cashier's hands:
                         the customer is taking their card back. She presses when
                         she has it, not when the software says it is available. --}}
                    <div class="mt-6 rounded-lg bg-emerald-50 p-4 dark:bg-emerald-950/40">
                        <div class="font-semibold text-emerald-700 dark:text-emerald-400">Terminale libero</div>
                        <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-400">
                            Prendilo e premi Riprova quando ce l'hai davanti.
                        </p>
                    </div>
                @else
                    <div class="mt-6 rounded-lg bg-amber-50 p-4 dark:bg-amber-950/40">
                        <div class="font-semibold text-amber-700 dark:text-amber-400">Terminale occupato</div>
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                            Lo sta usando «{{ $terminalBusyWith }}». Ti avviso appena si libera, oppure incassa in contanti.
                        </p>
                    </div>
                @endif
            @elseif ($attempt?->status === \App\Enums\CardTransactionStatus::Pending)
                {{-- The terminal is with the customer. The line it is showing
                     them is repeated here, so the cashier knows what is being
                     waited for instead of watching a spinner. --}}
                <div class="mt-6 flex items-center gap-3 rounded-lg bg-neutral-100 p-4 dark:bg-neutral-800">
                    <x-heroicon-o-credit-card class="h-6 w-6 shrink-0 animate-pulse text-neutral-500" />
                    <div class="min-w-0">
                        <div class="font-medium">In attesa del terminale</div>
                        <div class="truncate text-sm text-neutral-500">{{ $attempt->progress ?? 'Il cliente sta pagando sul POS.' }}</div>
                    </div>
                </div>
            @elseif ($attempt?->needsAnswer())
                <div class="mt-6 rounded-lg bg-amber-50 p-4 dark:bg-amber-950/40">
                    <div class="font-semibold text-amber-700 dark:text-amber-400">Esito sconosciuto</div>
                    {{-- She is holding the terminal: its screen and its own
                         printed copy say what happened, which is worth more
                         than anything this software could deduce. --}}
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        Il terminale non ha risposto. <strong>Guarda il terminale</strong>: se il pagamento è riuscito conferma qui sotto, altrimenti annulla e rifallo. Non ripremere Carta prima di aver guardato.
                    </p>
                    @if ($attempt->reason())
                        <p class="mt-2 text-sm text-neutral-500">{{ $attempt->reason() }}</p>
                    @endif
                </div>
            @elseif ($attempt !== null && ! $attempt->isApproved())
                <div class="mt-6 rounded-lg bg-red-50 p-4 dark:bg-red-950/40">
                    <div class="font-semibold text-red-700 dark:text-red-400">{{ $attempt->status->getLabel() }}</div>
                    @if ($attempt->reason())
                        <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $attempt->reason() }}</p>
                    @endif
                </div>
            @else
                <p class="mt-4 text-lg">La transazione è andata a buon fine?</p>
            @endif

            @error('checkout')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <div class="mt-6 space-y-2">
                @if ($attempt?->status === \App\Enums\CardTransactionStatus::Pending)
                    {{-- Nothing to press: the answer is coming, and offering a
                         way to close would mean abandoning a payment mid-way. --}}
                    <p class="text-center text-sm text-neutral-500">Attendi l'esito prima di cambiare metodo.</p>
                @elseif ($unresolvedPayment !== null)
                    <button type="button" wire:click="acknowledgeUnresolved"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                        <x-heroicon-o-check-circle class="h-5 w-5" />
                        Ho controllato, procedi
                    </button>
                    <button type="button" wire:click="cardToCash"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                        <x-heroicon-o-banknotes class="h-5 w-5" />
                        Paga in contanti
                    </button>
                    <button type="button" wire:click="closeCard"
                        class="w-full rounded-lg py-3 font-medium text-red-600">
                        Annulla
                    </button>
                @elseif ($terminalBusyWith !== null)
                    <button type="button" wire:click="retryCardPayment"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                        <x-heroicon-o-arrow-path class="h-5 w-5" />
                        Riprova sul terminale
                    </button>
                    <button type="button" wire:click="cardToCash"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                        <x-heroicon-o-banknotes class="h-5 w-5" />
                        Paga in contanti
                    </button>
                    <button type="button" wire:click="closeCard"
                        class="w-full rounded-lg py-3 font-medium text-red-600">
                        Annulla
                    </button>
                @else
                    @if ($attempt !== null && ! $attempt->needsAnswer())
                        <button type="button" wire:click="retryCardPayment"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-neutral-900 py-3 font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
                            <x-heroicon-o-arrow-path class="h-5 w-5" />
                            Riprova sul terminale
                        </button>
                    @endif

                    {{-- Always here, terminal or no terminal: the cashier is the
                         one looking at the POS, and a sale must never be stuck
                         behind an integration that cannot answer. --}}
                    <button type="button" wire:click="confirmCard" wire:loading.attr="disabled" wire:target="confirmCard"
                        class="flex w-full items-center justify-center gap-2 rounded-lg py-3 font-medium disabled:opacity-40 {{ $attempt === null ? 'bg-emerald-600 text-white' : 'border border-emerald-600 text-emerald-700 dark:text-emerald-400' }}">
                        <x-heroicon-o-check-circle class="h-5 w-5" />
                        Pagamento riuscito
                    </button>
                    <button type="button" wire:click="cardToCash"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-neutral-300 py-3 font-medium dark:border-neutral-700">
                        <x-heroicon-o-banknotes class="h-5 w-5" />
                        Paga in contanti
                    </button>
                    <button type="button" wire:click="closeCard"
                        class="w-full rounded-lg py-3 font-medium text-red-600">
                        Annulla
                    </button>
                @endif
            </div>
        </div>
    </div>
@endif

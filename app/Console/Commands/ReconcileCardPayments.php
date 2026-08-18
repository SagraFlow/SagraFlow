<?php

namespace App\Console\Commands;

use App\Enums\CardTransactionStatus;
use App\Models\CardTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Does the two things a person cannot: unblocks a till waiting on an answer
 * that is never coming, and makes visible the failure nobody witnesses.
 *
 * It decides nothing about money. Whether a payment went through is a question
 * for whoever is holding the terminal - its screen says so, and so does the
 * copy it printed - and this command's job is only to make sure that question
 * reaches someone while there is still time to act on it.
 */
class ReconcileCardPayments extends Command
{
    protected $signature = 'card:reconcile';

    protected $description = 'Chiude i pagamenti con carta rimasti in sospeso e segnala quelli da verificare.';

    /** An approved payment with no order is worth looking at after this long. */
    public const ORPHAN_AFTER_SECONDS = 120;

    public function handle(): int
    {
        $closed = $this->closeStale();
        $orphans = $this->reportOrphans();
        $unresolved = $this->countUnresolved();

        $this->line("Chiusi: {$closed}. Da verificare: incassi senza ordine {$orphans}, esiti sconosciuti {$unresolved}.");

        return self::SUCCESS;
    }

    /**
     * Attempts open far longer than a payment can take: the job never ran, or
     * died without saying so. They become the question they are - the money may
     * well have moved - and the till they belong to gets its buttons back.
     */
    protected function closeStale(): int
    {
        $stale = CardTransaction::query()
            ->where('status', CardTransactionStatus::Pending)
            ->where('created_at', '<', now()->subSeconds(CardTransaction::STUCK_AFTER_SECONDS))
            ->get();

        foreach ($stale as $attempt) {
            $attempt->settleAs(
                CardTransactionStatus::Unknown,
                'Il pagamento si è interrotto senza esito: controlla il terminale.',
            );
        }

        return $stale->count();
    }

    /**
     * Money taken with nothing sold: the till was gone when the answer came, so
     * nobody placed the order. Nothing here can be fixed automatically - the
     * order has to be made or the payment refunded, and both are decisions -
     * but a silent failure becomes a visible one.
     */
    protected function reportOrphans(): int
    {
        $orphans = CardTransaction::query()
            ->where('status', CardTransactionStatus::Approved)
            ->whereNull('order_id')
            ->where('completed_at', '<', now()->subSeconds(self::ORPHAN_AFTER_SECONDS))
            ->get();

        foreach ($orphans as $attempt) {
            Log::warning('Pagamento con carta approvato senza ordine.', [
                'card_transaction_id' => $attempt->id,
                'amount_cents' => $attempt->amount_cents,
                'stan' => $attempt->stan,
                'cash_register' => $attempt->cashRegister?->name,
                'completed_at' => $attempt->completed_at?->toDateTimeString(),
            ]);
        }

        return $orphans->count();
    }

    /**
     * Payments still waiting on somebody to look at a terminal. Counted, not
     * touched: what is left is the amount, the time and the station, which is
     * what a person needs to compare with the terminal's own report.
     */
    protected function countUnresolved(): int
    {
        return CardTransaction::query()
            ->where('status', CardTransactionStatus::Unknown)
            ->whereNull('order_id')
            ->count();
    }
}

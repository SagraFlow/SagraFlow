<?php

namespace App\Jobs;

use App\CardPayments\PaymentRunner;
use App\Enums\CardTransactionStatus;
use App\Models\CardTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one card payment against the terminal.
 *
 * It is a job because the customer takes as long as they take, and a till that
 * blocked for two minutes on a browser request would be a till nobody could
 * use. The attempt row is the source of truth; the queue only carries its id,
 * and the till watches the row.
 */
class SendCardPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Above the wait for an answer, below the supervisor's own timeout. */
    public int $timeout = 150;

    /**
     * Never more than one. A payment that ran once and left no answer is a
     * question for the terminal, never a message to send again.
     */
    public int $tries = 1;

    public function __construct(public int $cardTransactionId) {}

    public static function dispatchFor(CardTransaction $attempt): void
    {
        self::dispatch($attempt->id)->onQueue('payments');
    }

    public function handle(PaymentRunner $runner): void
    {
        $attempt = CardTransaction::find($this->cardTransactionId);

        // Already settled: confirmed by hand, or closed by the reconciler.
        if ($attempt === null || $attempt->status !== CardTransactionStatus::Pending) {
            return;
        }

        $runner->run($attempt);
    }

    /**
     * The worker died, or ran out of time, with the attempt still open. That is
     * precisely the case that must not be read as "nothing happened": the money
     * may have moved, and someone has to ask the terminal.
     */
    public function failed(?Throwable $exception): void
    {
        $attempt = CardTransaction::find($this->cardTransactionId);

        if ($attempt === null || $attempt->status !== CardTransactionStatus::Pending) {
            return;
        }

        $attempt->settleAs(
            CardTransactionStatus::Unknown,
            $exception?->getMessage() ?? 'Il pagamento si è interrotto senza esito.',
        );
    }
}

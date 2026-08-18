<?php

namespace App\CardPayments;

use App\CardPayments\Protocol\EcrRequest;
use App\CardPayments\Protocol\EcrResponse;
use App\Enums\CardTransactionStatus;
use App\Exceptions\EcrProtocolException;
use App\Exceptions\EcrUnsentException;
use App\Models\CardTerminal;
use App\Models\CardTransaction;
use App\Models\CashRegister;

/**
 * Takes a card payment on the terminal of a station.
 *
 * Nothing here decides anything about an order, and nothing here guesses. When
 * the terminal does not answer, the attempt is left saying so: the cashier is
 * holding the terminal and can read the outcome off its screen, which is worth
 * more than any deduction this code could make from an amount and a clock.
 */
class PaymentRunner
{
    /**
     * How long a customer may keep the terminal before we stop waiting.
     *
     * Below the job's own timeout on purpose, so that a terminal which never
     * answers is closed by this code - which knows whether the message left -
     * rather than by a worker being killed mid-wait. The chain reads:
     * this (120) < job (150) < supervisor (180) < the queue's retry_after (300).
     */
    public const RESPONSE_TIMEOUT = 120;

    public function __construct(private EcrConnection $connection) {}

    /**
     * Opens an attempt: takes the terminal for this station and writes the row
     * before anything is sent, so a payment can never happen without a record
     * of it existing first.
     *
     * Returns null when another station is on the terminal - the caller says so
     * and lets the cashier decide, rather than queueing behind a transaction of
     * unknown length.
     */
    public function start(CashRegister $register, int $amountCents): ?CardTransaction
    {
        $terminal = $register->cardTerminal;

        if ($terminal === null || ! $terminal->claim($register)) {
            return null;
        }

        return CardTransaction::create([
            'card_terminal_id' => $terminal->id,
            'cash_register_id' => $register->id,
            'terminal_id' => $terminal->terminal_id,
            'ecr_id' => $this->ecrId($register),
            'amount_cents' => $amountCents,
            'status' => CardTransactionStatus::Pending,
        ]);
    }

    /**
     * Asks the terminal for the money and records the answer.
     *
     * A message that never left is Failed - nothing was charged. Silence after
     * it left is Unknown, and the terminal keeps its claim in that case: asking
     * it later what its last transaction was only means something as long as no
     * other station has started one in between.
     */
    public function run(CardTransaction $attempt): CardTransaction
    {
        $terminal = $attempt->terminal;

        if ($terminal === null) {
            $attempt->settleAs(CardTransactionStatus::Failed, 'Terminale non più configurato.');

            return $attempt->fresh();
        }

        try {
            $payload = $this->connection->request(
                host: $terminal->ip_address,
                port: $terminal->port,
                payload: EcrRequest::payment($attempt->terminal_id, $attempt->ecr_id, $attempt->amount_cents),
                readTimeout: self::RESPONSE_TIMEOUT,
                onProgress: fn (string $line) => $attempt->update(['progress' => $line]),
                // One shot for money: a payment is never sent twice on its own.
                attempts: 1,
            );
        } catch (EcrProtocolException $exception) {
            $this->settleUnanswered($attempt, $terminal, $exception);

            return $attempt->fresh();
        }

        $attempt->settleWith(EcrResponse::paymentResult($payload));
        $this->releaseTerminal($attempt, $terminal);

        return $attempt->fresh();
    }

    /**
     * Silence and refusal are not the same thing. A message the terminal never
     * took cannot have charged anyone; one that left without an answer might
     * have, and only that case keeps hold of the terminal.
     */
    protected function settleUnanswered(CardTransaction $attempt, CardTerminal $terminal, EcrProtocolException $exception): void
    {
        $unsent = $exception instanceof EcrUnsentException;

        $attempt->settleAs(
            $unsent ? CardTransactionStatus::Failed : CardTransactionStatus::Unknown,
            $exception->getMessage(),
        );

        // Silence keeps the terminal: asking it later what its last transaction
        // was only means anything as long as nobody else has started one.
        if ($unsent) {
            $this->releaseTerminal($attempt, $terminal);
        }
    }

    protected function releaseTerminal(CardTransaction $attempt, CardTerminal $terminal): void
    {
        $register = $attempt->cashRegister;

        if ($register !== null) {
            $terminal->release($register);
        }
    }

    /** The station's identity in the protocol: its id, in eight digits. */
    protected function ecrId(CashRegister $register): string
    {
        return str_pad((string) $register->getKey(), 8, '0', STR_PAD_LEFT);
    }
}

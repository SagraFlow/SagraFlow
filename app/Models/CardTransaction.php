<?php

namespace App\Models;

use App\CardPayments\Protocol\PaymentResult;
use App\Enums\CardPaymentOutcome;
use App\Enums\CardTransactionStatus;
use Database\Factories\CardTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to take a card payment on a terminal.
 *
 * It is written before the terminal is asked anything, and it is what remains
 * afterwards: an order exists only if the payment was approved, so this row is
 * the only place where a payment that ended badly - or did not visibly end at
 * all - can still be read.
 */
class CardTransaction extends Model
{
    /** @use HasFactory<CardTransactionFactory> */
    use HasFactory;

    /**
     * How long an attempt may stay open before it stops meaning "the customer
     * is paying" and starts meaning "nobody is coming back with an answer".
     * Past the job's own timeout, so a slow customer is never given up on while
     * the payment is genuinely running.
     */
    public const STUCK_AFTER_SECONDS = 200;

    protected $fillable = [
        'card_terminal_id', 'cash_register_id', 'order_id', 'terminal_id', 'ecr_id',
        'amount_cents', 'status', 'outcome', 'amount_from_host_cents', 'authorization_code',
        'stan', 'transaction_type', 'card_type', 'pan_last4', 'host_datetime', 'acquirer_id',
        'description', 'currency_exchanged', 'manual', 'progress', 'error', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CardTransactionStatus::class,
            'outcome' => CardPaymentOutcome::class,
            'amount_cents' => 'integer',
            'amount_from_host_cents' => 'integer',
            'currency_exchanged' => 'boolean',
            'manual' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(CardTerminal::class, 'card_terminal_id');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Writes down what the terminal said, and closes the attempt on it. */
    public function settleWith(PaymentResult $result): void
    {
        $this->update([
            'status' => $result->isApproved() ? CardTransactionStatus::Approved : CardTransactionStatus::Declined,
            'outcome' => $result->outcome,
            'amount_from_host_cents' => $result->amountCents,
            'authorization_code' => $result->authorizationCode,
            'stan' => $result->stan,
            'transaction_type' => $result->transactionType,
            'card_type' => $result->cardType,
            'pan_last4' => $result->panLast4,
            'host_datetime' => $result->hostDateTime,
            'acquirer_id' => $result->acquirerId,
            'description' => $result->description,
            'currency_exchanged' => $result->currencyExchanged,
            'progress' => null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Closes the attempt without an answer from the terminal. The wording is
     * kept: it is what the cashier will be shown, and what someone reading the
     * row tomorrow has to go on.
     */
    public function settleAs(CardTransactionStatus $status, ?string $error = null): void
    {
        $this->update([
            'status' => $status,
            'error' => $error,
            'progress' => null,
            'completed_at' => now(),
        ]);
    }

    public function isApproved(): bool
    {
        return $this->status === CardTransactionStatus::Approved;
    }

    /**
     * Open far longer than a payment can take: the worker died, or never ran.
     * The till must not be left waiting on it - a cashier with a queue in front
     * of her needs a way forward, and this is what gives her one.
     */
    public function isStuck(): bool
    {
        return $this->status === CardTransactionStatus::Pending
            && $this->created_at?->addSeconds(self::STUCK_AFTER_SECONDS)->isPast();
    }

    /**
     * Waiting on an answer nobody may guess: either it said so, or it has been
     * open so long that it amounts to the same thing.
     */
    public function needsAnswer(): bool
    {
        return $this->status === CardTransactionStatus::Unknown || $this->isStuck();
    }

    /** Why it failed, in the fewest words that are still true. */
    public function reason(): ?string
    {
        return $this->description ?? $this->error;
    }
}

<?php

namespace App\CardPayments\Protocol;

use App\Enums\CardPaymentOutcome;

/**
 * How a payment ended, as the terminal told it.
 *
 * The full card number arrives in the message and stops here: only the last
 * four digits are carried further, and the raw payload is never kept. A PAN in
 * a sagra's database is a problem nobody needs to have.
 */
final readonly class PaymentResult
{
    public function __construct(
        public string $terminalId,
        public CardPaymentOutcome $outcome,
        public ?int $amountCents = null,
        public ?string $panLast4 = null,
        public ?string $transactionType = null,
        public ?string $authorizationCode = null,
        /** Date and time from the host, as DDDHHMM (day of the year). */
        public ?string $hostDateTime = null,
        public ?string $cardType = null,
        public ?string $acquirerId = null,
        /** Sequence number of the transaction on the terminal. */
        public ?string $stan = null,
        /** Progressive number of the online operation. */
        public ?string $idOnline = null,
        public ?string $actionCode = null,
        /** Why it was refused, in the terminal's own words. */
        public ?string $description = null,
        /** The customer paid in the currency of their card. */
        public bool $currencyExchanged = false,
    ) {}

    public function isApproved(): bool
    {
        return $this->outcome->isApproved();
    }

    public function cardTypeLabel(): ?string
    {
        return match ($this->cardType) {
            '1' => 'Bancomat',
            '2' => 'Credito',
            '3' => 'Altra carta',
            default => null,
        };
    }

    /**
     * What identifies this transaction on the terminal. Used to tell a result
     * we have already seen from one we have not - which is the whole question
     * when asking the terminal to repeat its last outcome.
     *
     * The STAN alone, deliberately: on a real transaction the online id came
     * back as all zeros, so a reference built on both would have been the same
     * string for two different payments.
     */
    public function reference(): ?string
    {
        return $this->stan;
    }
}

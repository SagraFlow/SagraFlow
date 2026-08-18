<?php

namespace App\CardPayments\Protocol;

use App\Exceptions\EcrProtocolException;

/**
 * The messages the till sends. Every field is fixed width and positional, so
 * each one is built by name here and never assembled by hand at the call site.
 */
final class EcrRequest
{
    /**
     * The payment we send is the one with the extended result: its answer
     * carries back the amount the host approved, along with the authorisation
     * code and the sequence number. None of it is needed to take the money -
     * it is what a row has to carry to be matched, months later, against the
     * acquirer's own report.
     */
    public const PAYMENT = 'X';

    public const TERMINAL_STATUS = 's';

    public const LAST_RESULT = 'G';

    /** Eight digits of cents: a hair under ten million euro. */
    public const MAX_AMOUNT_CENTS = 99_999_999;

    /** The contract code travels in a 128 character field. */
    public const RECEIPT_TEXT_LENGTH = 128;

    /**
     * A payment for the given amount.
     *
     * @param  string  $terminalId  the 8 digits Nexi assigned to the terminal
     * @param  string  $cashRegisterId  the 8 digits identifying the till asking
     * @param  string|null  $receiptText  contract code printed on the receipt
     */
    public static function payment(
        string $terminalId,
        string $cashRegisterId,
        int $amountCents,
        ?string $receiptText = null,
    ): string {
        if ($amountCents < 1 || $amountCents > self::MAX_AMOUNT_CENTS) {
            throw new EcrProtocolException("Importo fuori intervallo: {$amountCents} centesimi.");
        }

        return self::header($terminalId, self::PAYMENT)
            .self::identifier($cashRegisterId, 'cash register id')
            .'0'   // no additional data
            .'00'  // reserved
            .'0'   // the card has not been presented yet
            .'0'   // let the terminal recognise debit or credit on its own
            .str_pad((string) $amountCents, 8, '0', STR_PAD_LEFT)
            .self::receiptText($receiptText)
            .str_repeat('0', 8); // reserved
    }

    /**
     * "Are you there, and in what state?" - the probe behind the terminal's
     * health, and the one message that is safe to send at any time.
     */
    public static function terminalStatus(string $terminalId): string
    {
        return self::header($terminalId, self::TERMINAL_STATUS);
    }

    /**
     * "Tell me again how the last transaction ended." The way out of not
     * knowing, when the answer to a payment never arrived.
     */
    public static function lastResult(string $terminalId, string $cashRegisterId): string
    {
        return self::header($terminalId, self::LAST_RESULT)
            .self::identifier($cashRegisterId, 'cash register id')
            .'0'    // no additional data
            .'000'; // reserved
    }

    /** Terminal id, the reserved byte, and the letter naming the message. */
    private static function header(string $terminalId, string $code): string
    {
        return self::identifier($terminalId, 'terminal id').'0'.$code;
    }

    private static function identifier(string $value, string $what): string
    {
        if (preg_match('/^\d{8}$/', $value) !== 1) {
            throw new EcrProtocolException("Valore non valido per {$what}: \"{$value}\" (servono 8 cifre).");
        }

        return $value;
    }

    /**
     * Right aligned in its field, as the protocol wants. Cut rather than
     * refused: a contract code too long is a configuration mistake, and it must
     * not be the reason a payment cannot be taken.
     */
    private static function receiptText(?string $text): string
    {
        $text = substr($text ?? '', 0, self::RECEIPT_TEXT_LENGTH);

        return str_pad($text, self::RECEIPT_TEXT_LENGTH, ' ', STR_PAD_LEFT);
    }
}

<?php

namespace App\CardPayments\Protocol;

use App\Enums\CardPaymentOutcome;
use App\Exceptions\EcrProtocolException;

/**
 * Reads the messages the terminal sends back. Positions are those of the
 * protocol, counted from one in the documentation and from zero here.
 */
final class EcrResponse
{
    public const PAYMENT_RESULT = 'E';

    /**
     * The same outcome, from a terminal whose contract offers the customer to
     * pay in the currency of their card. Everything we read sits at the same
     * positions; what follows is the exchange, which is the customer's business
     * and the receipt's, not ours.
     */
    public const PAYMENT_RESULT_EXCHANGED = 'V';

    public const TERMINAL_STATUS = 's';

    /** Everything up to and including the result code. */
    private const RESULT_HEADER_LENGTH = 12;

    public static function code(string $payload): ?string
    {
        return strlen($payload) >= 10 ? $payload[9] : null;
    }

    /**
     * The outcome of a payment. Only the framing and the layout can be wrong
     * here: a refusal is a well formed message like any other, and comes back
     * as a result, never as an exception.
     */
    public static function paymentResult(string $payload): PaymentResult
    {
        self::assertLength($payload, self::RESULT_HEADER_LENGTH, 'esito pagamento');

        $code = self::code($payload);

        if ($code !== self::PAYMENT_RESULT && $code !== self::PAYMENT_RESULT_EXCHANGED) {
            throw new EcrProtocolException("Atteso un esito di pagamento, ricevuto il messaggio \"{$code}\".");
        }

        $outcome = CardPaymentOutcome::tryFrom(substr($payload, 10, 2));

        if ($outcome === null) {
            throw new EcrProtocolException('Codice di esito sconosciuto: "'.substr($payload, 10, 2).'".');
        }

        // The tail (card type onwards) belongs to the extended result. A
        // terminal answering in the short form simply leaves it out, and every
        // field of it stays null rather than making the whole message unusable.
        return new PaymentResult(
            terminalId: substr($payload, 0, 8),
            outcome: $outcome,
            amountCents: self::digits($payload, 74, 8),
            panLast4: $outcome->isApproved() ? self::panLast4($payload) : null,
            transactionType: $outcome->isApproved() ? self::text($payload, 31, 3) : null,
            authorizationCode: $outcome->isApproved() ? self::text($payload, 34, 6) : null,
            hostDateTime: $outcome->isApproved() ? self::text($payload, 40, 7) : null,
            cardType: self::text($payload, 47, 1),
            acquirerId: self::text($payload, 48, 11),
            stan: self::text($payload, 59, 6),
            idOnline: self::text($payload, 65, 6),
            actionCode: self::text($payload, 71, 3),
            description: $outcome->isApproved() ? null : self::text($payload, 12, 24),
            currencyExchanged: $code === self::PAYMENT_RESULT_EXCHANGED,
        );
    }

    public static function terminalStatus(string $payload): TerminalStatus
    {
        self::assertLength($payload, 31, 'stato terminale');

        $code = self::code($payload);

        if ($code !== self::TERMINAL_STATUS) {
            throw new EcrProtocolException("Atteso uno stato terminale, ricevuto il messaggio \"{$code}\".");
        }

        return new TerminalStatus(
            terminalId: substr($payload, 0, 8),
            code: $payload[30],
            dateTime: self::text($payload, 20, 10),
            softwareRelease: self::text($payload, 31, 8),
        );
    }

    /** The four digits that may be kept, out of a number that may not be. */
    private static function panLast4(string $payload): ?string
    {
        $pan = self::text($payload, 12, 19);

        return $pan === null ? null : substr($pan, -4);
    }

    private static function text(string $payload, int $offset, int $length): ?string
    {
        if (strlen($payload) < $offset + 1) {
            return null;
        }

        $value = trim(substr($payload, $offset, $length));

        return $value === '' ? null : $value;
    }

    private static function digits(string $payload, int $offset, int $length): ?int
    {
        $value = self::text($payload, $offset, $length);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private static function assertLength(string $payload, int $needed, string $what): void
    {
        if (strlen($payload) < $needed) {
            throw new EcrProtocolException(sprintf(
                'Messaggio di %s troppo corto: %d byte, ne servono almeno %d.',
                $what,
                strlen($payload),
                $needed,
            ));
        }
    }
}

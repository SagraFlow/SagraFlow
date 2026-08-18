<?php

namespace App\CardPayments\Protocol;

use App\Exceptions\EcrProtocolException;

/**
 * The envelope of the Nexi ECR protocol: STX, the message, ETX, and a checksum.
 *
 * Everything here is bytes in and bytes out, with no socket and no model in
 * sight, so the wire format can be pinned by tests to the byte.
 *
 * Which checksum we write is a choice - see LrcVariant for why there is one to
 * make. What we accept is every reading of it: a message whose checksum matches
 * any of them is intact by any account, and refusing it would mean failing a
 * real payment over a convention.
 */
final class EcrFrame
{
    public const STX = 0x02;

    public const ETX = 0x03;

    public const ACK = 0x06;

    public const NAK = 0x15;

    /** Start of a progress line. */
    public const SOH = 0x01;

    /** End of a progress line. */
    public const EOT = 0x04;

    /** A progress line is always this long, between SOH and EOT. */
    public const PROGRESS_LENGTH = 20;

    /** Wraps an application message for transmission. */
    public static function encode(string $payload, ?LrcVariant $variant = null): string
    {
        if ($payload === '') {
            throw new EcrProtocolException('Messaggio vuoto.');
        }

        $variant ??= LrcVariant::default();
        $framed = chr(self::STX).$payload.chr(self::ETX);

        return $framed.chr($variant->compute($framed));
    }

    public static function ack(?LrcVariant $variant = null): string
    {
        return self::control(self::ACK, $variant);
    }

    public static function nak(?LrcVariant $variant = null): string
    {
        return self::control(self::NAK, $variant);
    }

    /**
     * Reads one frame. Returns null when the buffer does not hold a whole one
     * yet, so a caller reading from a socket can simply keep appending.
     *
     * @return array{frame: DecodedFrame, length: int}|null
     */
    public static function read(string $buffer): ?array
    {
        if ($buffer === '') {
            return null;
        }

        return match (ord($buffer[0])) {
            self::SOH => self::readProgress($buffer),
            self::ACK, self::NAK => self::readControl($buffer),
            self::STX => self::readApplication($buffer),
            default => throw new EcrProtocolException(sprintf('Byte iniziale inatteso: 0x%02X.', ord($buffer[0]))),
        };
    }

    /**
     * Reads exactly one whole frame, for callers that already have it in hand
     * (the tests, and a fake terminal).
     */
    public static function decode(string $frame): DecodedFrame
    {
        $read = self::read($frame);

        if ($read === null) {
            throw new EcrProtocolException('Messaggio incompleto.');
        }

        if ($read['length'] !== strlen($frame)) {
            throw new EcrProtocolException('Byte in eccesso dopo la fine del messaggio.');
        }

        return $read['frame'];
    }

    private static function control(int $byte, ?LrcVariant $variant): string
    {
        $variant ??= LrcVariant::default();
        // No STX on a control frame: it is the byte and its ETX, nothing else.
        //
        // Worth knowing before "fixing" this: padosoft/laravel-ecr17, the other
        // PHP implementation of this protocol, folds STX into the checksum of an
        // ACK too when in that mode. Doing the same made the terminal answer our
        // confirmations with "LRC is not matched" on its screen, and computing
        // over the bytes actually sent made the complaint stop. Checked against
        // a SmartPOS Mini, not reasoned about.
        $framed = chr($byte).chr(self::ETX);

        return $framed.chr($variant->compute($framed));
    }

    /**
     * @return array{frame: DecodedFrame, length: int}|null
     */
    private static function readControl(string $buffer): ?array
    {
        if (strlen($buffer) < 3) {
            return null;
        }

        if (ord($buffer[1]) !== self::ETX) {
            throw new EcrProtocolException('Conferma senza ETX.');
        }

        self::assertChecksum(substr($buffer, 0, 2), ord($buffer[2]));

        $type = ord($buffer[0]) === self::ACK ? EcrFrameType::Ack : EcrFrameType::Nak;

        return ['frame' => new DecodedFrame($type, ''), 'length' => 3];
    }

    /**
     * @return array{frame: DecodedFrame, length: int}|null
     */
    private static function readProgress(string $buffer): ?array
    {
        $length = self::PROGRESS_LENGTH + 2;

        if (strlen($buffer) < $length) {
            return null;
        }

        if (ord($buffer[$length - 1]) !== self::EOT) {
            throw new EcrProtocolException('Riga di avanzamento senza EOT.');
        }

        // No checksum on these, and no answer expected either.
        return [
            'frame' => new DecodedFrame(EcrFrameType::Progress, substr($buffer, 1, self::PROGRESS_LENGTH)),
            'length' => $length,
        ];
    }

    /**
     * @return array{frame: DecodedFrame, length: int}|null
     */
    private static function readApplication(string $buffer): ?array
    {
        $etx = strpos($buffer, chr(self::ETX), 1);

        if ($etx === false || strlen($buffer) < $etx + 2) {
            return null;
        }

        $payload = substr($buffer, 1, $etx - 1);
        self::assertChecksum(substr($buffer, 0, $etx + 1), ord($buffer[$etx + 1]));

        return [
            'frame' => new DecodedFrame(EcrFrameType::Application, $payload),
            'length' => $etx + 2,
        ];
    }

    /**
     * Intact under any reading of the checksum is intact enough. Takes the
     * frame as it arrived, up to and including ETX.
     */
    private static function assertChecksum(string $framed, int $received): void
    {
        foreach (LrcVariant::cases() as $variant) {
            if ($variant->compute($framed) === $received) {
                return;
            }
        }

        throw new EcrProtocolException(sprintf(
            'Checksum errato: ricevuto 0x%02X, atteso 0x%02X.',
            $received,
            LrcVariant::default()->compute($framed),
        ));
    }
}

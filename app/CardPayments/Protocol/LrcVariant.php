<?php

namespace App\CardPayments\Protocol;

/**
 * How the checksum is computed over a frame.
 *
 * The documentation says only that the LRC is "an exclusive or [of] any message
 * byte, using as base value 0x7F", without saying which bytes count as message
 * bytes or confirming the seed with an example. That leaves a handful of
 * readings, and a terminal accepts exactly one of them - so they are named here
 * and tried against the real device, rather than guessed at once and hard-coded.
 *
 * Once a terminal has told us which one it wants, that is the default below.
 */
enum LrcVariant: string
{
    /** Seed 0x7F over the message and ETX. */
    case Base7fWithEtx = 'base7f-etx';

    /** Seed 0x7F over the message alone. */
    case Base7fMessageOnly = 'base7f-message';

    /** Seed 0x7F over STX, the message and ETX. */
    case Base7fWithStxEtx = 'base7f-stx-etx';

    /** Plain XOR (seed 0) over the message and ETX. */
    case ZeroWithEtx = 'zero-etx';

    /** Plain XOR over the message alone. */
    case ZeroMessageOnly = 'zero-message';

    /** Plain XOR over STX, the message and ETX. */
    case ZeroWithStxEtx = 'zero-stx-etx';

    /**
     * What the SmartPOS in hand actually accepts, found by asking it: seed
     * 0x7F over STX, the message and ETX. The other readings were refused with
     * "LCR is not matched" on the terminal's screen, so this is measured, not
     * chosen - and if another model ever disagrees, card:probe finds its
     * reading the same way.
     */
    public static function default(): self
    {
        return self::Base7fWithStxEtx;
    }

    public function seed(): int
    {
        return match ($this) {
            self::Base7fWithEtx, self::Base7fMessageOnly, self::Base7fWithStxEtx => 0x7F,
            self::ZeroWithEtx, self::ZeroMessageOnly, self::ZeroWithStxEtx => 0x00,
        };
    }

    public function includesStx(): bool
    {
        return $this === self::Base7fWithStxEtx || $this === self::ZeroWithStxEtx;
    }

    public function includesEtx(): bool
    {
        return $this !== self::Base7fMessageOnly && $this !== self::ZeroMessageOnly;
    }

    /**
     * The checksum byte for a frame, under this reading.
     *
     * What comes in is the frame exactly as it travels, from STX (where there
     * is one) through ETX, and each reading drops from it the bytes it does not
     * count. It works this way, rather than by adding STX and ETX around a
     * message, because an ACK carries an ETX and no STX: a reading that counted
     * a byte which is not on the wire would send a checksum of something that
     * was never transmitted.
     */
    public function compute(string $frame): int
    {
        $bytes = $frame;

        if (! $this->includesStx() && $bytes !== '' && ord($bytes[0]) === EcrFrame::STX) {
            $bytes = substr($bytes, 1);
        }

        if (! $this->includesEtx() && $bytes !== '' && ord($bytes[strlen($bytes) - 1]) === EcrFrame::ETX) {
            $bytes = substr($bytes, 0, -1);
        }

        $lrc = $this->seed();

        for ($i = 0; $i < strlen($bytes); $i++) {
            $lrc ^= ord($bytes[$i]);
        }

        return $lrc;
    }

    public function label(): string
    {
        return match ($this) {
            self::Base7fWithEtx => 'base 0x7F, messaggio + ETX',
            self::Base7fMessageOnly => 'base 0x7F, solo messaggio',
            self::Base7fWithStxEtx => 'base 0x7F, STX + messaggio + ETX',
            self::ZeroWithEtx => 'XOR semplice, messaggio + ETX',
            self::ZeroMessageOnly => 'XOR semplice, solo messaggio',
            self::ZeroWithStxEtx => 'XOR semplice, STX + messaggio + ETX',
        };
    }
}

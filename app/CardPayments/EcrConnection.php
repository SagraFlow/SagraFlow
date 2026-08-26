<?php

namespace App\CardPayments;

use App\CardPayments\Protocol\DecodedFrame;
use App\CardPayments\Protocol\EcrFrame;
use App\CardPayments\Protocol\EcrFrameType;
use App\CardPayments\Protocol\LrcVariant;
use App\Exceptions\EcrProtocolException;
use App\Exceptions\EcrUnsentException;
use Closure;

/**
 * One conversation with a terminal over TCP: we open the connection, send a
 * message, and read until its answer arrives.
 *
 * The two timeouts are separate on purpose. Opening the connection is given
 * room because the terminals are on wifi and their radio wakes from sleep: a
 * till goes minutes between one customer and the next, and opens measured on
 * this network reached three seconds with the hall still empty. An answer can
 * take minutes of its own - a card looked for in a wallet, a PIN typed twice -
 * and that wait is only bounded so that a terminal which never replies does not
 * hold the till forever.
 */
class EcrConnection
{
    /** The protocol repeats a message up to three times on NAK or silence. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Which reading of the checksum we write with. A terminal accepts one, and
     * says so plainly when it is the wrong one, so it can be varied from the
     * outside while we find out.
     */
    public function __construct(private ?LrcVariant $lrc = null)
    {
        $this->lrc ??= LrcVariant::default();
    }

    /**
     * Sends a request and returns the payload of the application message that
     * answers it.
     *
     * @param  Closure(string): void|null  $onProgress  called with each progress
     *                                                  line, so the cashier can be told what the terminal is asking the
     *                                                  customer to do while she waits
     */
    public function request(
        string $host,
        int $port,
        string $payload,
        int $connectTimeout = 10,
        int $readTimeout = 180,
        ?Closure $onProgress = null,
        int $attempts = self::MAX_ATTEMPTS,
    ): string {
        $socket = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);

        if ($socket === false) {
            throw new EcrUnsentException("Connessione a {$host}:{$port} fallita: {$errstr} ({$errno}).");
        }

        try {
            stream_set_timeout($socket, $readTimeout);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                if (@fwrite($socket, EcrFrame::encode($payload, $this->lrc)) === false) {
                    throw new EcrUnsentException("Invio a {$host}:{$port} fallito.");
                }

                $answer = $this->listen($socket, $readTimeout, $onProgress);

                // A NAK means it did not like what it read: send it again.
                if ($answer->type !== EcrFrameType::Nak) {
                    return $answer->payload;
                }
            }

            // Refused, therefore never processed: whatever it was, it did not
            // happen, and the caller can say so without hedging.
            throw new EcrUnsentException('Il terminale ha rifiutato il messaggio (checksum non riconosciuto?).');
        } finally {
            fclose($socket);
        }
    }

    /**
     * Reads frames until the answer to our message arrives. ACKs and progress
     * lines are steps along the way, not the answer: the first only says the
     * terminal took the message, the second is there to be shown and needs no
     * reply of ours.
     *
     * @param  resource  $socket
     * @param  Closure(string): void|null  $onProgress
     */
    protected function listen($socket, int $readTimeout, ?Closure $onProgress): DecodedFrame
    {
        $buffer = '';
        $heard = false;
        $deadline = microtime(true) + $readTimeout;

        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 4096);

            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($socket);

                if ($info['timed_out'] ?? false) {
                    throw $this->unanswered($heard, 'Il terminale non ha risposto entro il tempo massimo.');
                }

                if (feof($socket)) {
                    throw $this->unanswered($heard, 'Il terminale ha chiuso la connessione senza rispondere.');
                }

                continue;
            }

            $heard = true;
            $buffer .= $chunk;

            while (($read = EcrFrame::read($buffer)) !== null) {
                $frame = $read['frame'];
                $buffer = substr($buffer, $read['length']);

                if ($frame->isProgress()) {
                    $onProgress?->call($this, $frame->progressText());

                    continue;
                }

                if ($frame->type === EcrFrameType::Ack) {
                    continue; // taken, now we wait for the real answer
                }

                if ($frame->type === EcrFrameType::Nak) {
                    return $frame;
                }

                // An application message: confirm it, and we are done.
                @fwrite($socket, EcrFrame::ack($this->lrc));

                return $frame;
            }
        }

        throw $this->unanswered($heard, 'Il terminale non ha risposto entro il tempo massimo.');
    }

    /**
     * Which of the two failures a wait without an answer is.
     *
     * A terminal that said nothing at all never took the message: no
     * acknowledgement, no line for the customer, not a byte. Nothing was
     * processed, therefore nothing was charged, and the till can say so and move
     * on. One that spoke first may have gone on to charge the card whatever
     * happened to the line afterwards, and that is the question only the person
     * holding the terminal can answer.
     *
     * The doubt is kept on the slightest evidence on purpose: the mistake that
     * costs money is calling an attempt harmless when it was not.
     */
    protected function unanswered(bool $heard, string $message): EcrProtocolException
    {
        return $heard
            ? new EcrProtocolException($message)
            : new EcrUnsentException('Il terminale non ha preso il messaggio: nessun importo è stato inviato.');
    }
}

<?php

namespace App\Console\Commands;

use App\CardPayments\EcrConnection;
use App\CardPayments\Protocol\EcrRequest;
use App\CardPayments\Protocol\EcrResponse;
use App\Console\Commands\Concerns\ChoosesCardTerminal;
use App\Exceptions\EcrProtocolException;
use Illuminate\Console\Command;

/**
 * Asks the terminal how its last transaction ended: the way out of not knowing,
 * when the answer to a payment never arrived.
 *
 * Read it with care. The terminal keeps the last result without any notion of
 * whose it was, so what comes back may belong to an earlier transaction: the
 * amount, and the STAN, are what tell one from the other.
 */
class ShowLastCardResult extends Command
{
    use ChoosesCardTerminal;

    protected $signature = 'card:last {terminal? : Nome del terminale} {--timeout=30 : Secondi di attesa} {--lrc= : Forza una variante di checksum}';

    protected $description = 'Chiede a un terminale POS l\'esito dell\'ultima transazione.';

    public function handle(): int
    {
        $terminal = $this->terminal();

        if ($terminal === null) {
            return self::FAILURE;
        }

        try {
            $payload = (new EcrConnection($this->lrcVariant()))->request(
                host: $terminal->ip_address,
                port: $terminal->port,
                payload: EcrRequest::lastResult($terminal->terminal_id, $this->cashRegisterId($terminal)),
                readTimeout: (int) $this->option('timeout'),
                onProgress: fn (string $line) => $this->line("  ... {$line}"),
            );
        } catch (EcrProtocolException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->showResult(EcrResponse::paymentResult($payload));
        $this->warn('Questo è l\'ultimo esito che il terminale ricorda, non necessariamente quello che stai cercando: confronta importo e STAN.');

        return self::SUCCESS;
    }
}

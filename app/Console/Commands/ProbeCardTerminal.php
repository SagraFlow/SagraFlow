<?php

namespace App\Console\Commands;

use App\CardPayments\EcrConnection;
use App\CardPayments\Protocol\EcrRequest;
use App\CardPayments\Protocol\EcrResponse;
use App\CardPayments\Protocol\LrcVariant;
use App\CardPayments\Protocol\TerminalStatus;
use App\Console\Commands\Concerns\ChoosesCardTerminal;
use App\Exceptions\EcrProtocolException;
use App\Models\CardTerminal;
use Illuminate\Console\Command;

/**
 * Asks a terminal how it is. Reads and nothing else - no amount, no card, no
 * money - so it is the safe way to find out whether the network, the address,
 * the port and the terminal id are right, before anything is ever charged.
 *
 * It doubles as the way we settle how the terminal computes its checksum: when
 * the usual reading is refused, every other one is tried and the one that gets
 * an answer is reported. Being refused costs nothing here, which is exactly why
 * the question is asked with this message and not with a payment.
 */
class ProbeCardTerminal extends Command
{
    use ChoosesCardTerminal;

    protected $signature = 'card:probe {terminal? : Nome del terminale}
        {--timeout=10 : Secondi di attesa}
        {--lrc= : Forza una variante di checksum}';

    protected $description = 'Interroga lo stato di un terminale POS (nessun pagamento).';

    public function handle(): int
    {
        $terminal = $this->terminal();

        if ($terminal === null) {
            return self::FAILURE;
        }

        $forced = $this->option('lrc') !== null ? LrcVariant::tryFrom((string) $this->option('lrc')) : null;

        if ($this->option('lrc') !== null && $forced === null) {
            $this->error('Variante di checksum sconosciuta. Valori: '.collect(LrcVariant::cases())->pluck('value')->implode(', '));

            return self::FAILURE;
        }

        $this->line("Interrogo {$terminal->name} su {$terminal->ip_address}:{$terminal->port} (terminal id {$terminal->terminal_id})...");

        $variants = $forced !== null ? [$forced] : $this->variantsToTry();

        foreach ($variants as $index => $variant) {
            if ($index > 0) {
                $this->line("Riprovo con checksum: {$variant->label()}");
            }

            $payload = $this->probeWith($terminal, $variant);

            if ($payload === null) {
                continue;
            }

            $this->report(EcrResponse::terminalStatus($payload), $variant, announced: $index > 0);

            return self::SUCCESS;
        }

        $this->error('Nessuna variante di checksum è stata accettata dal terminale.');
        $this->line('Se il terminale mostra un errore di checksum, il messaggio arriva: restano da verificare terminal id e configurazione dell\'app Scambio Importo.');

        return self::FAILURE;
    }

    /** The usual reading first, then the others, each tried once. */
    protected function probeWith(CardTerminal $terminal, LrcVariant $variant): ?string
    {
        try {
            return (new EcrConnection($variant))->request(
                host: $terminal->ip_address,
                port: $terminal->port,
                payload: EcrRequest::terminalStatus($terminal->terminal_id),
                readTimeout: (int) $this->option('timeout'),
                onProgress: fn (string $line) => $this->line("  ... {$line}"),
                attempts: 1,
            );
        } catch (EcrProtocolException $exception) {
            $this->line('  '.$exception->getMessage());

            return null;
        }
    }

    /**
     * @return array<int, LrcVariant>
     */
    protected function variantsToTry(): array
    {
        return collect(LrcVariant::cases())
            ->sortByDesc(fn (LrcVariant $variant): bool => $variant === LrcVariant::default())
            ->values()
            ->all();
    }

    protected function report(TerminalStatus $status, LrcVariant $variant, bool $announced): void
    {
        $this->newLine();
        $this->table(['Campo', 'Valore'], [
            ['Terminal id', $status->terminalId],
            ['Stato', $status->label()." ({$status->code})"],
            ['Data/ora', $status->dateTime ?? '-'],
            ['Software', $status->softwareRelease ?? '-'],
            ['Checksum accettato', $variant->label()." [{$variant->value}]"],
        ]);

        if ($announced) {
            $this->warn("Il terminale vuole il checksum \"{$variant->value}\": va reso predefinito in LrcVariant::default().");
        }

        if (! $status->isOperative()) {
            $this->warn('Il terminale risponde ma non è operativo: non può accettare pagamenti in questo stato.');
        }
    }
}

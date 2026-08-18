<?php

namespace App\Console\Commands\Concerns;

use App\CardPayments\Protocol\LrcVariant;
use App\CardPayments\Protocol\PaymentResult;
use App\Models\CardTerminal;

/**
 * Shared by the hand-run terminal commands - both of which only ever read.
 * Nothing here can take money: a payment happens at a till, where it is
 * recorded, or it does not happen.
 */
trait ChoosesCardTerminal
{
    protected function terminal(): ?CardTerminal
    {
        $name = $this->argument('terminal');

        if ($name !== null) {
            $terminal = CardTerminal::query()->where('name', $name)->first();

            if ($terminal === null) {
                $this->error("Nessun terminale chiamato \"{$name}\".");
            }

            return $terminal;
        }

        $terminals = CardTerminal::query()->orderBy('name')->get();

        if ($terminals->isEmpty()) {
            $this->error('Nessun terminale configurato: aggiungilo dal pannello.');

            return null;
        }

        if ($terminals->count() === 1) {
            return $terminals->first();
        }

        $chosen = $this->choice('Quale terminale?', $terminals->pluck('name')->all());

        return $terminals->firstWhere('name', $chosen);
    }

    /**
     * The 8 digit identity of the till in the protocol. Derived from the id of
     * the station the terminal serves, so the terminal's own log shows which
     * one asked; a terminal attached to none is still worth testing, and gets
     * the first identifier.
     */
    protected function cashRegisterId(CardTerminal $terminal): string
    {
        $id = $terminal->cashRegisters()->orderBy('id')->value('id') ?? 1;

        return str_pad((string) $id, 8, '0', STR_PAD_LEFT);
    }

    /**
     * The checksum reading to write with, when the command lets one be forced.
     * Only needed while a terminal's convention is still being settled: once it
     * is the default, nobody passes this.
     */
    protected function lrcVariant(): ?LrcVariant
    {
        $value = $this->hasOption('lrc') ? $this->option('lrc') : null;

        return $value === null ? null : LrcVariant::from((string) $value);
    }

    protected function showResult(PaymentResult $result): void
    {
        $this->newLine();
        $this->table(['Campo', 'Valore'], [
            ['Esito', $result->outcome->getLabel()." ({$result->outcome->value})"],
            ['Importo dall\'host', $result->amountCents !== null ? number_format($result->amountCents / 100, 2, ',', '.').' €' : '-'],
            ['Motivo', $result->description ?? '-'],
            ['Autorizzazione', $result->authorizationCode ?? '-'],
            ['Carta', trim(($result->cardTypeLabel() ?? '').' '.($result->panLast4 !== null ? '****'.$result->panLast4 : '')) ?: '-'],
            ['Lettura', $result->transactionType ?? '-'],
            ['Data/ora host', $result->hostDateTime ?? '-'],
            ['Acquirer', $result->acquirerId ?? '-'],
            ['STAN / ID online', $result->reference() ?? '-'],
        ]);
    }
}

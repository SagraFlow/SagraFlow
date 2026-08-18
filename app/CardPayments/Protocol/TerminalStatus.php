<?php

namespace App\CardPayments\Protocol;

/**
 * What the terminal answers when asked how it is. The codes are the
 * manufacturer's, so they are kept as they arrive and only the one that matters
 * to us - operative, ready to take a payment - is given a name.
 */
final readonly class TerminalStatus
{
    /** Configured and operative. */
    public const OPERATIVE = '2';

    public function __construct(
        public string $terminalId,
        public string $code,
        /** As DDMMYYhhmm, the terminal's own clock. */
        public ?string $dateTime = null,
        public ?string $softwareRelease = null,
    ) {}

    public function isOperative(): bool
    {
        return $this->code === self::OPERATIVE;
    }

    public function label(): string
    {
        return match ($this->code) {
            '0' => 'Non configurato',
            '1' => 'Configurato, senza DLL',
            self::OPERATIVE => 'Operativo',
            '3' => 'Non allineato',
            '4' => 'Chiavi da rigenerare',
            '5' => 'DLL in attesa dal server',
            '6' => 'Aggiornamento software in attesa',
            default => "Stato {$this->code}",
        };
    }
}

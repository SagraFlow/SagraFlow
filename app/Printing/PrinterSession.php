<?php

namespace App\Printing;

use App\Enums\PrinterStatus;
use App\Exceptions\PrinterException;

/**
 * An open connection to a printer: transmits documents and reads the printer's
 * real-time status over the same socket. Held open for a whole batch so the
 * documents of an order leave one after the other, with no reconnect in
 * between: an Epson network interface accepts a single connection at a time and
 * a reconnect attempted while it is printing is slow and can be refused.
 */
class PrinterSession
{
    /**
     * @param  resource  $socket
     */
    public function __construct(
        private $socket,
        private PrinterStatusParser $parser,
        private string $host,
        private int $port,
        private int $writeTimeout,
    ) {}

    /**
     * Reads the printer's state from the ESC/POS real-time status queries
     * (DLE EOT). A printer that stays silent reports Offline: inside an open
     * session that means "too busy to answer" as often as it means "in error",
     * so the caller decides how much weight to give it.
     */
    public function status(int $readTimeoutMs = 300): PrinterStatus
    {
        stream_set_timeout($this->socket, 0, $readTimeoutMs * 1000);

        $bytes = [];

        foreach ([1, 2, 4] as $n) {
            $bytes[$n] = $this->query($n);
        }

        return $this->parser->parse($bytes);
    }

    /**
     * Transmits raw ESC/POS bytes to the printer.
     */
    public function write(string $data): void
    {
        stream_set_timeout($this->socket, $this->writeTimeout);

        if (@fwrite($this->socket, $data) === false) {
            throw new PrinterException("Invio dei dati a {$this->host}:{$this->port} fallito.");
        }
    }

    /**
     * Sends a command and reads up to $maxBytes of its reply, returning early
     * when the printer stops sending, so a silent printer never blocks past the
     * read timeout.
     */
    public function request(string $command, int $maxBytes, int $readTimeoutMs = 500): string
    {
        stream_set_timeout($this->socket, 0, $readTimeoutMs * 1000);

        if (@fwrite($this->socket, $command) === false) {
            return '';
        }

        $buffer = '';

        while (strlen($buffer) < $maxBytes) {
            $chunk = @fread($this->socket, $maxBytes - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Sends a single DLE EOT n status query and reads the one-byte reply,
     * returning null when the printer does not answer within the read timeout
     * set by the caller.
     */
    private function query(int $n): ?int
    {
        if (@fwrite($this->socket, "\x10\x04".chr($n)) === false) {
            return null;
        }

        $response = @fread($this->socket, 1);

        if ($response === false || $response === '') {
            return null;
        }

        return ord($response);
    }
}

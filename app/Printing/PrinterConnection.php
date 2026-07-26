<?php

namespace App\Printing;

use App\Enums\PrinterStatus;
use App\Exceptions\PrinterException;

/**
 * Talks to a network printer over a TCP socket: transmits raw ESC/POS bytes and
 * queries the printer's real-time status (DLE EOT), with bounded timeouts so an
 * offline or silent printer never hangs the worker.
 */
class PrinterConnection
{
    public function __construct(private PrinterStatusParser $parser) {}

    public function send(string $host, int $port, string $data, int $timeout = 5): void
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            throw new PrinterException("Connessione a {$host}:{$port} fallita: {$errstr} ({$errno}).");
        }

        try {
            stream_set_timeout($socket, $timeout);

            if (@fwrite($socket, $data) === false) {
                throw new PrinterException("Invio dei dati a {$host}:{$port} fallito.");
            }
        } finally {
            fclose($socket);
        }
    }

    /**
     * Probes the printer's readiness via the ESC/POS real-time status queries.
     * Returns Offline when unreachable OR reachable but silent (a compliant
     * Epson printer answers whenever it is readable, so silence means offline /
     * in error), and a concrete state (Ready/PaperOut/CoverOpen/Error) otherwise.
     */
    public function probe(string $host, int $port, int $connectTimeout = 2, int $readTimeoutMs = 300): PrinterStatus
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);

        if ($socket === false) {
            return PrinterStatus::Offline;
        }

        try {
            stream_set_timeout($socket, 0, $readTimeoutMs * 1000);

            $bytes = [];
            foreach ([1, 2, 4] as $n) {
                $bytes[$n] = $this->query($socket, $n);
            }

            return $this->parser->parse($bytes);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Whether the printer is configured to report its status while offline,
     * i.e. memory switch Msw1-3 (Condition for BUSY) is ON = "Receive buffer
     * full" only. When OFF (the factory default "Receive buffer full or
     * Offline"), the printer stops reading the buffer while offline and never
     * answers status queries in an error state. Returns null when it cannot be
     * determined (unreachable / unexpected reply).
     */
    public function offlineStatusEnabled(string $host, int $port, int $connectTimeout = 2, int $readTimeoutMs = 500): ?bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);

        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, 0, $readTimeoutMs * 1000);
            // GS ( E <Function 4>: transmit the settings of memory switch 1.
            @fwrite($socket, "\x1d\x28\x45\x02\x00\x04\x01");

            return $this->parseOfflineStatusSetting($this->readBytes($socket, 11));
        } finally {
            fclose($socket);
        }
    }

    /**
     * Parses the GS ( E <Function 4> reply (Header 0x37, Identifier 0x21, 8
     * bytes bit8..bit1 as '0'/'1', NUL) and returns whether Msw1-3 is ON.
     */
    public function parseOfflineStatusSetting(string $response): ?bool
    {
        // Need at least header + identifier + up to bit 3 (offset 7).
        if (strlen($response) < 8 || $response[0] !== "\x37" || $response[1] !== "\x21") {
            return null;
        }

        // Transmitted bit8..bit1 start at offset 2, so Msw1-3 is at offset 7.
        return $response[7] === '1';
    }

    /**
     * Enables reporting of status while offline by turning Msw1-3 ON, wrapped in
     * the user setting mode (GS ( E Functions 1 -> 3 -> 2). Function 2 performs a
     * software reset that applies the setting, so no manual power cycle is needed.
     * Writes to non-volatile memory: only call when the setting is not already ON.
     */
    public function enableOfflineStatus(string $host, int $port, int $connectTimeout = 3, int $readTimeoutMs = 1000): void
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);

        if ($socket === false) {
            throw new PrinterException("Connessione a {$host}:{$port} fallita: {$errstr} ({$errno}).");
        }

        try {
            stream_set_timeout($socket, 0, $readTimeoutMs * 1000);

            // Function 1: enter user setting mode, then wait for the mode-change notice.
            @fwrite($socket, "\x1d\x28\x45\x03\x00\x01\x49\x4e");
            $this->readBytes($socket, 3);

            // Function 3: set Msw1-3 (Condition for BUSY) ON, leaving the rest unchanged.
            $bits = chr(50).chr(50).chr(50).chr(50).chr(50).chr(49).chr(50).chr(50); // b8..b1, b3 = ON
            @fwrite($socket, "\x1d\x28\x45\x0a\x00\x03\x01".$bits);

            // Function 2: end user setting mode + software reset (applies the change).
            @fwrite($socket, "\x1d\x28\x45\x04\x00\x02\x4f\x55\x54");
        } finally {
            fclose($socket);
        }
    }

    /**
     * Reads up to $max bytes, returning early when the printer stops sending
     * (read timeout), so a silent printer never blocks past the timeout.
     */
    private function readBytes($socket, int $max): string
    {
        $buffer = '';

        while (strlen($buffer) < $max) {
            $chunk = @fread($socket, $max - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Sends a single DLE EOT n status query and reads the one-byte reply,
     * returning null when the printer does not answer within the read timeout.
     */
    private function query($socket, int $n): ?int
    {
        if (@fwrite($socket, "\x10\x04".chr($n)) === false) {
            return null;
        }

        $response = @fread($socket, 1);

        if ($response === false || $response === '') {
            return null;
        }

        return ord($response);
    }
}

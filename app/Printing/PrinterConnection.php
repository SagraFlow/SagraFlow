<?php

namespace App\Printing;

use App\Enums\PrinterStatus;
use App\Exceptions\PrinterException;
use App\Printing\Concerns\QuietSockets;
use Closure;

/**
 * Talks to a network printer over a TCP socket: opens sessions that transmit
 * raw ESC/POS bytes and query the printer's real-time status (DLE EOT), with
 * bounded timeouts so an offline or silent printer never hangs the worker.
 */
class PrinterConnection
{
    use QuietSockets;

    public function __construct(private PrinterStatusParser $parser) {}

    /**
     * Opens one connection and hands it to the callback as a session, closing it
     * afterwards. Everything the printer is told or asked within the callback
     * travels over that single socket.
     *
     * @template TReturn
     *
     * @param  Closure(PrinterSession): TReturn  $callback
     * @return TReturn
     */
    public function session(string $host, int $port, Closure $callback, int $connectTimeout = 5, ?int $writeTimeout = null): mixed
    {
        // A closure with references, not an arrow function: fsockopen reports why
        // it failed through those two, and an arrow function captures by value.
        $socket = $this->quietly(function () use ($host, $port, &$errno, &$errstr, $connectTimeout) {
            return fsockopen($host, $port, $errno, $errstr, $connectTimeout);
        });

        if ($socket === false) {
            throw new PrinterException("Connessione a {$host}:{$port} fallita: {$errstr} ({$errno}).");
        }

        try {
            return $callback(new PrinterSession($socket, $this->parser, $host, $port, $writeTimeout ?? $connectTimeout));
        } finally {
            fclose($socket);
        }
    }

    public function send(string $host, int $port, string $data, int $timeout = 5): void
    {
        $this->session($host, $port, fn (PrinterSession $session) => $session->write($data), $timeout);
    }

    /**
     * Probes the printer's state via the ESC/POS real-time status queries.
     * Offline means exactly one thing here: the connection would not open, so
     * the printer is switched off, unplugged, or gone from the network. A
     * printer that answers reports its real state (Ready/PaperOut/CoverOpen/
     * Error); one that answers nothing is Unknown, because a full receive buffer
     * silences it just as an error would.
     */
    public function probe(string $host, int $port, int $connectTimeout = 2, int $readTimeoutMs = 300): PrinterStatus
    {
        try {
            return $this->session(
                $host,
                $port,
                fn (PrinterSession $session) => $session->status($readTimeoutMs),
                $connectTimeout,
            );
        } catch (PrinterException) {
            return PrinterStatus::Offline;
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
        try {
            return $this->session($host, $port, fn (PrinterSession $session): ?bool => $this->parseOfflineStatusSetting(
                // GS ( E <Function 4>: transmit the settings of memory switch 1.
                $session->request("\x1d\x28\x45\x02\x00\x04\x01", 11, $readTimeoutMs),
            ), $connectTimeout);
        } catch (PrinterException) {
            return null;
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
        $this->session($host, $port, function (PrinterSession $session) use ($readTimeoutMs): void {
            // Function 1: enter user setting mode, then wait for the mode-change notice.
            $session->request("\x1d\x28\x45\x03\x00\x01\x49\x4e", 3, $readTimeoutMs);

            // Function 3: set Msw1-3 (Condition for BUSY) ON, leaving the rest unchanged.
            $bits = chr(50).chr(50).chr(50).chr(50).chr(50).chr(49).chr(50).chr(50); // b8..b1, b3 = ON
            $session->write("\x1d\x28\x45\x0a\x00\x03\x01".$bits);

            // Function 2: end user setting mode + software reset (applies the change).
            $session->write("\x1d\x28\x45\x04\x00\x02\x4f\x55\x54");
        }, $connectTimeout);
    }
}

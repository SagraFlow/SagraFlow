<?php

namespace App\Printing;

use App\Exceptions\PrinterException;

/**
 * Sends raw ESC/POS bytes to a network printer over a TCP socket, with a
 * bounded connection timeout so an offline printer never hangs the worker.
 */
class PrinterConnection
{
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
}

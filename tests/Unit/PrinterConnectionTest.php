<?php

use App\Enums\PrinterStatus;
use App\Exceptions\PrinterException;
use App\Printing\PrinterConnection;
use App\Printing\PrinterSession;
use App\Printing\PrinterStatusParser;

function connection(): PrinterConnection
{
    return new PrinterConnection(new PrinterStatusParser);
}

/**
 * A listening socket standing in for a printer, and the port it answers on.
 *
 * @return array{0: resource, 1: int}
 */
function fakePrinter(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    return [$server, (int) explode(':', stream_socket_get_name($server, false))[1]];
}

it('transmits every document of a session over the same connection', function () {
    [$server, $port] = fakePrinter();

    connection()->session('127.0.0.1', $port, function (PrinterSession $session): void {
        $session->write('SCONTRINO');
        $session->write('TAGLIANDINO');
    });

    $printer = stream_socket_accept($server, 1);
    stream_set_timeout($printer, 0, 100_000);

    expect(fread($printer, 64))->toBe('SCONTRINOTAGLIANDINO');

    fclose($printer);
    fclose($server);
});

it('hands over a document too large for one go, all of it', function () {
    [$server, $port] = fakePrinter();

    // Far more than a receipt with a logo, so the write takes several goes.
    $document = str_repeat('X', 200_000);

    connection()->session('127.0.0.1', $port, fn (PrinterSession $session) => $session->write($document));

    $printer = stream_socket_accept($server, 1);
    stream_set_timeout($printer, 1);

    $received = '';
    while (strlen($received) < strlen($document)) {
        $chunk = fread($printer, 65536);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $received .= $chunk;
    }

    expect(strlen($received))->toBe(strlen($document));

    fclose($printer);
    fclose($server);
});

it('fails instead of half-printing when the printer stops taking data', function () {
    [$server, $port] = fakePrinter();

    // Nothing ever reads on the other side: the socket buffers fill and the
    // write stalls, which is what a printer whose paper cannot keep up does.
    connection()->session(
        '127.0.0.1',
        $port,
        fn (PrinterSession $session) => $session->write(str_repeat('X', 64 * 1024 * 1024)),
        connectTimeout: 1,
        writeTimeout: 1,
    );

    fclose($server);
})->throws(PrinterException::class, 'interrotto dopo');

it('reports a reachable but silent printer as offline', function () {
    [$server, $port] = fakePrinter();

    $status = connection()->probe('127.0.0.1', $port, readTimeoutMs: 50);

    expect($status)->toBe(PrinterStatus::Offline);

    fclose($server);
});

it('reports an unreachable printer as offline instead of failing', function () {
    [$server, $port] = fakePrinter();
    fclose($server); // nothing listening on the port any more

    expect(connection()->probe('127.0.0.1', $port, connectTimeout: 1))->toBe(PrinterStatus::Offline);
});

it('fails a send to an unreachable printer, so the job can park it', function () {
    [$server, $port] = fakePrinter();
    fclose($server);

    connection()->send('127.0.0.1', $port, 'SCONTRINO', timeout: 1);
})->throws(PrinterException::class);

it('reads whether offline status reporting is enabled from the memory-switch reply', function (string $response, ?bool $expected) {
    expect(connection()->parseOfflineStatusSetting($response))->toBe($expected);
})->with([
    // Header 0x37, Identifier 0x21, then bit8..bit1 as '0'/'1', then NUL.
    'Msw1-3 off (default)' => ["\x37\x21".'11000000'."\x00", false],
    'Msw1-3 on (configured)' => ["\x37\x21".'00000100'."\x00", true],
    'no reply' => ['', null],
    'unexpected header' => ["\x00\x00".'00000100'."\x00", null],
]);

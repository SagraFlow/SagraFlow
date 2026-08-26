<?php

use App\CardPayments\EcrConnection;
use App\CardPayments\Protocol\DecodedFrame;
use App\CardPayments\Protocol\EcrFrame;
use App\CardPayments\Protocol\EcrFrameType;
use App\Exceptions\EcrProtocolException;
use App\Exceptions\EcrUnsentException;

/** A stream holding what the terminal would have said, ready to be read. */
function terminalSaying(string $bytes)
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** Runs the frame exchange against a stream, without opening any socket. */
function listenOn($stream, ?Closure $onProgress = null): DecodedFrame
{
    $connection = new EcrConnection;

    return Closure::bind(
        fn (): DecodedFrame => $connection->listen($stream, 5, $onProgress),
        null,
        EcrConnection::class,
    )();
}

it('waits past the acknowledgement for the answer that matters', function () {
    // The ACK only says the terminal took the message; the outcome comes later.
    $stream = terminalSaying(EcrFrame::ack().EcrFrame::encode('00099887'.'0'.'E'.'00'));

    $frame = listenOn($stream);

    expect($frame->type)->toBe(EcrFrameType::Application)
        ->and($frame->messageCode())->toBe('E');
});

it('confirms the answer it received', function () {
    $stream = terminalSaying(EcrFrame::encode('00099887'.'0'.'E'.'00'));

    listenOn($stream);

    // Our ACK is written back on the same stream, after what we read.
    $written = stream_get_contents($stream, -1, 0);

    expect(str_ends_with($written, EcrFrame::ack()))->toBeTrue();
});

it('hands every progress line to the caller, in order', function () {
    $progress = fn (string $text): string => chr(0x01).str_pad($text, 20).chr(0x04);
    $stream = terminalSaying(
        $progress('INSERIRE CARTA')
        .$progress('DIGITARE PIN')
        .EcrFrame::encode('00099887'.'0'.'E'.'00')
    );

    $seen = [];
    listenOn($stream, function (string $line) use (&$seen): void {
        $seen[] = $line;
    });

    // These are what the cashier reads while the customer is on the terminal.
    expect($seen)->toBe(['INSERIRE CARTA', 'DIGITARE PIN']);
});

it('reports a refusal of the message itself, so it can be sent again', function () {
    $frame = listenOn(terminalSaying(EcrFrame::nak()));

    expect($frame->type)->toBe(EcrFrameType::Nak);
});

it('does not pretend an answer arrived when the line went quiet', function () {
    // Not an acknowledgement, not a line for the customer, not a byte: the
    // terminal never took the message, so the till may say plainly that nobody
    // was charged instead of sending someone to go and look.
    listenOn(terminalSaying(''));
})->throws(EcrUnsentException::class);

it('keeps the doubt when the terminal spoke before going quiet', function (string $said) {
    $thrown = null;

    try {
        listenOn(terminalSaying($said));
    } catch (EcrProtocolException $exception) {
        $thrown = $exception;
    }

    // It answered something, so it had the message. Whatever became of the line
    // afterwards, the card may have been charged, and that is a question for
    // whoever is holding the terminal.
    expect($thrown)->toBeInstanceOf(EcrProtocolException::class)
        ->and($thrown)->not->toBeInstanceOf(EcrUnsentException::class);
})->with([
    'an acknowledgement' => EcrFrame::ack(),
    'a line for the customer' => chr(0x01).'INSERIRE CARTA      '.chr(0x04),
    'half an answer' => "\x02".'00099887',
]);

it('does not mistake half an answer for a whole one', function () {
    $whole = EcrFrame::encode('00099887'.'0'.'E'.'00');

    listenOn(terminalSaying(substr($whole, 0, 5)));
})->throws(EcrProtocolException::class);

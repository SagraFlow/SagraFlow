<?php

use App\CardPayments\Protocol\DecodedFrame;
use App\CardPayments\Protocol\EcrFrame;
use App\CardPayments\Protocol\EcrFrameType;
use App\CardPayments\Protocol\LrcVariant;
use App\Exceptions\EcrProtocolException;

it('computes each reading of the checksum the way it says', function () {
    // Worked out by hand, so a change of formula cannot pass unnoticed. What
    // goes in is the frame as it travels: STX, the message, ETX.
    $framed = chr(0x02).'AB'.chr(0x03);

    expect(LrcVariant::Base7fWithEtx->compute($framed))->toBe(0x7F ^ 0x41 ^ 0x42 ^ 0x03)
        ->and(LrcVariant::Base7fMessageOnly->compute($framed))->toBe(0x7F ^ 0x41 ^ 0x42)
        ->and(LrcVariant::Base7fWithStxEtx->compute($framed))->toBe(0x7F ^ 0x02 ^ 0x41 ^ 0x42 ^ 0x03)
        ->and(LrcVariant::ZeroWithEtx->compute($framed))->toBe(0x41 ^ 0x42 ^ 0x03)
        ->and(LrcVariant::ZeroMessageOnly->compute($framed))->toBe(0x41 ^ 0x42)
        ->and(LrcVariant::ZeroWithStxEtx->compute($framed))->toBe(0x02 ^ 0x41 ^ 0x42 ^ 0x03);
});

it('checksums an ack over what an ack actually is', function () {
    // An ACK has an ETX and no STX. Counting a byte that is not on the wire
    // sends a checksum of something never transmitted, and the terminal says so
    // - which is exactly the bug this pins down.
    $ack = EcrFrame::ack(LrcVariant::Base7fWithStxEtx);

    expect(strlen($ack))->toBe(3)
        ->and(ord($ack[2]))->toBe(0x7F ^ 0x06 ^ 0x03);
});

it('wraps a message between stx and etx and closes it with the checksum', function () {
    $frame = EcrFrame::encode('AB');

    expect($frame)->toBe(chr(0x02).'AB'.chr(0x03).chr(LrcVariant::default()->compute(chr(0x02).'AB'.chr(0x03))))
        ->and(strlen($frame))->toBe(5);
});

it('refuses to wrap nothing', function () {
    EcrFrame::encode('');
})->throws(EcrProtocolException::class);

it('reads back what it wrote', function () {
    $payload = '00000001'.'0'.'E'.'00';

    $frame = EcrFrame::decode(EcrFrame::encode($payload));

    expect($frame->type)->toBe(EcrFrameType::Application)
        ->and($frame->payload)->toBe($payload)
        ->and($frame->messageCode())->toBe('E');
});

it('builds an ack and a nak the other end can read', function () {
    expect(EcrFrame::decode(EcrFrame::ack())->type)->toBe(EcrFrameType::Ack)
        ->and(EcrFrame::decode(EcrFrame::nak())->type)->toBe(EcrFrameType::Nak)
        ->and(strlen(EcrFrame::ack()))->toBe(3);
});

it('reads a progress line, which carries no checksum and wants no answer', function () {
    $line = str_pad('INSERIRE CARTA', 20);
    $frame = EcrFrame::decode(chr(0x01).$line.chr(0x04));

    expect($frame->type)->toBe(EcrFrameType::Progress)
        ->and($frame->isProgress())->toBeTrue()
        ->and($frame->progressText())->toBe('INSERIRE CARTA');
});

it('writes the checksum the way it is told to', function () {
    $frame = EcrFrame::encode('AB', LrcVariant::ZeroMessageOnly);

    expect(ord($frame[strlen($frame) - 1]))->toBe(0x41 ^ 0x42);
});

it('reads back its own ack and nak under every reading', function () {
    foreach (LrcVariant::cases() as $variant) {
        expect(EcrFrame::decode(EcrFrame::ack($variant))->type)->toBe(EcrFrameType::Ack)
            ->and(EcrFrame::decode(EcrFrame::nak($variant))->type)->toBe(EcrFrameType::Nak);
    }
});

it('accepts an answer whose checksum follows any of the readings', function () {
    // The documentation does not settle which bytes count, so a terminal taking
    // another reading must not have its answer thrown away over a convention.
    foreach (LrcVariant::cases() as $variant) {
        expect(EcrFrame::decode(EcrFrame::encode('ABC', $variant))->payload)->toBe('ABC');
    }
});

it('rejects a message whose checksum is neither', function () {
    EcrFrame::decode(chr(0x02).'ABC'.chr(0x03).chr(0x00));
})->throws(EcrProtocolException::class);

it('waits instead of guessing when only half a message has arrived', function () {
    $frame = EcrFrame::encode('00000001'.'0'.'E'.'00');

    expect(EcrFrame::read(substr($frame, 0, 4)))->toBeNull()
        ->and(EcrFrame::read(substr($frame, 0, strlen($frame) - 1)))->toBeNull()
        ->and(EcrFrame::read($frame)['length'])->toBe(strlen($frame));
});

it('reads one message at a time out of a stream of them', function () {
    // A progress line and the result can arrive in the same read.
    $stream = chr(0x01).str_pad('ATTENDERE', 20).chr(0x04).EcrFrame::encode('payload');

    $first = EcrFrame::read($stream);
    $second = EcrFrame::read(substr($stream, $first['length']));

    expect($first['frame']->type)->toBe(EcrFrameType::Progress)
        ->and($second['frame']->payload)->toBe('payload');
});

it('refuses a frame that starts with something unexpected', function () {
    EcrFrame::read('Z'.'unframed');
})->throws(EcrProtocolException::class);

it('names the message by its code, and nothing else', function () {
    expect((new DecodedFrame(EcrFrameType::Progress, 'ATTENDERE'))->messageCode())->toBeNull();
});

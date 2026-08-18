<?php

use App\CardPayments\Protocol\EcrRequest;
use App\CardPayments\Protocol\EcrResponse;
use App\Enums\CardPaymentOutcome;
use App\Exceptions\EcrProtocolException;

/**
 * Builds an extended payment result the way the terminal lays it out, so a test
 * can name a field instead of counting characters.
 *
 * @param  array<string, string>  $fields
 */
function paymentResultPayload(string $result = '00', array $fields = []): string
{
    $payload = '00099887'          // terminal id
        .'0'                       // reserved
        .'E'                       // message code
        .$result;

    if ($result === '00') {
        $payload .= str_pad($fields['pan'] ?? '4321', 19, '0', STR_PAD_LEFT)
            .($fields['type'] ?? 'ICC')
            .str_pad($fields['auth'] ?? 'A1B2C3', 6)
            .($fields['when'] ?? '2291435');
    } else {
        $payload .= str_pad($fields['description'] ?? 'CARTA RIFIUTATA', 24)
            .str_repeat('0', 11);
    }

    return $payload
        .($fields['cardType'] ?? '2')
        .str_pad($fields['acquirer'] ?? 'NEXI', 11)
        .str_pad($fields['stan'] ?? '000123', 6, '0', STR_PAD_LEFT)
        .str_pad($fields['idOnline'] ?? '000456', 6, '0', STR_PAD_LEFT)
        .($fields['actionCode'] ?? '000')
        .str_pad($fields['amount'] ?? '00001250', 8, '0', STR_PAD_LEFT)
        .str_repeat('0', 10);
}

it('lays out a payment request field by field', function () {
    $payload = EcrRequest::payment('00099887', '00000002', 1250, 'CONTRATTO123');

    expect(strlen($payload))->toBe(167)
        ->and(substr($payload, 0, 8))->toBe('00099887')   // terminal id
        ->and($payload[8])->toBe('0')                     // reserved
        ->and($payload[9])->toBe('X')                     // extended result
        ->and(substr($payload, 10, 8))->toBe('00000002')  // cash register id
        ->and($payload[18])->toBe('0')                    // no additional data
        ->and(substr($payload, 19, 2))->toBe('00')        // reserved
        ->and($payload[21])->toBe('0')                    // card not yet inserted
        ->and($payload[22])->toBe('0')                    // let the terminal recognise the card
        ->and(substr($payload, 23, 8))->toBe('00001250')  // amount in cents
        ->and(substr($payload, 31, 128))->toBe(str_pad('CONTRATTO123', 128, ' ', STR_PAD_LEFT))
        ->and(substr($payload, 159, 8))->toBe('00000000');
});

it('writes the amount in cents, padded to eight digits', function () {
    expect(substr(EcrRequest::payment('00099887', '00000001', 5), 23, 8))->toBe('00000005')
        ->and(substr(EcrRequest::payment('00099887', '00000001', 99_999_999), 23, 8))->toBe('99999999');
});

it('refuses an amount the field cannot hold, or none at all', function (int $cents) {
    EcrRequest::payment('00099887', '00000001', $cents);
})->with([0, -100, 100_000_000])->throws(EcrProtocolException::class);

it('refuses an identifier that is not eight digits', function () {
    EcrRequest::payment('999', '00000001', 100);
})->throws(EcrProtocolException::class);

it('keeps the receipt text inside its field rather than blocking the payment', function () {
    // A contract code too long is a configuration mistake, and it must not be
    // the reason a customer cannot pay.
    $payload = EcrRequest::payment('00099887', '00000001', 100, str_repeat('A', 200));

    expect(strlen($payload))->toBe(167)
        ->and(substr($payload, 31, 128))->toBe(str_repeat('A', 128));
});

it('lays out the status and last result requests', function () {
    $status = EcrRequest::terminalStatus('00099887');
    $last = EcrRequest::lastResult('00099887', '00000002');

    expect($status)->toBe('00099887'.'0'.'s')
        ->and($last)->toBe('00099887'.'0'.'G'.'00000002'.'0'.'000');
});

it('reads an approved payment, amount included', function () {
    $result = EcrResponse::paymentResult(paymentResultPayload());

    expect($result->outcome)->toBe(CardPaymentOutcome::Approved)
        ->and($result->isApproved())->toBeTrue()
        ->and($result->amountCents)->toBe(1250)
        ->and($result->transactionType)->toBe('ICC')
        ->and($result->authorizationCode)->toBe('A1B2C3')
        ->and($result->hostDateTime)->toBe('2291435')
        ->and($result->cardTypeLabel())->toBe('Credito')
        ->and($result->acquirerId)->toBe('NEXI')
        ->and($result->stan)->toBe('000123')
        ->and($result->reference())->toBe('000123')
        ->and($result->description)->toBeNull();
});

it('keeps only the last four digits of the card', function () {
    // The full number arrives and must go no further: nothing in this project
    // has any use for it, and storing it would be a problem of its own.
    $result = EcrResponse::paymentResult(paymentResultPayload(fields: ['pan' => '5333171234567890']));

    $properties = array_keys(get_object_vars($result));

    expect($result->panLast4)->toBe('7890')
        ->and($properties)->not->toContain('pan');
});

it('reads a refusal with the reason the terminal gave', function () {
    $result = EcrResponse::paymentResult(paymentResultPayload('01', ['description' => 'FONDI INSUFFICIENTI']));

    expect($result->outcome)->toBe(CardPaymentOutcome::Declined)
        ->and($result->isApproved())->toBeFalse()
        ->and($result->description)->toBe('FONDI INSUFFICIENTI')
        ->and($result->panLast4)->toBeNull()
        ->and($result->authorizationCode)->toBeNull();
});

it('reads the card-not-present and unknown-request outcomes', function () {
    expect(EcrResponse::paymentResult(paymentResultPayload('05'))->outcome)->toBe(CardPaymentOutcome::CardNotPresent)
        ->and(EcrResponse::paymentResult(paymentResultPayload('09'))->outcome)->toBe(CardPaymentOutcome::UnknownTag);
});

it('reads a short answer without losing the outcome', function () {
    // A terminal that answers in the plain form leaves the tail out: the fields
    // it did not send stay null, the result it did send is still readable.
    $short = '00099887'.'0'.'E'.'00';

    $result = EcrResponse::paymentResult($short);

    expect($result->outcome)->toBe(CardPaymentOutcome::Approved)
        ->and($result->amountCents)->toBeNull()
        ->and($result->stan)->toBeNull()
        ->and($result->reference())->toBeNull();
});

it('identifies a transaction by its stan alone', function () {
    // Seen on a real payment: the online id came back as all zeros, so a
    // reference built on both would name two different payments the same.
    $result = EcrResponse::paymentResult(paymentResultPayload(fields: ['stan' => '000103', 'idOnline' => '000000']));

    expect($result->reference())->toBe('000103')
        ->and($result->idOnline)->toBe('000000');
});

it('reads an outcome that came back with a currency exchange', function () {
    // A contract offering the customer their own currency answers with "V"
    // instead of "E". The fields we read sit in the same places, so refusing it
    // would throw away a payment that went through.
    $payload = substr_replace(paymentResultPayload(), 'V', 9, 1);

    $result = EcrResponse::paymentResult($payload);

    expect($result->outcome)->toBe(CardPaymentOutcome::Approved)
        ->and($result->currencyExchanged)->toBeTrue()
        ->and($result->amountCents)->toBe(1250)
        ->and($result->stan)->toBe('000123');
});

it('refuses to read an outcome out of another kind of message', function () {
    EcrResponse::paymentResult('00099887'.'0'.'s'.'00');
})->throws(EcrProtocolException::class);

it('refuses an outcome code it does not know', function () {
    EcrResponse::paymentResult('00099887'.'0'.'E'.'77');
})->throws(EcrProtocolException::class);

it('reads the terminal status', function () {
    $payload = '00099887'.'0'.'s'.str_repeat('0', 10).'1708261230'.'2'.'01.02.03';

    $status = EcrResponse::terminalStatus($payload);

    expect($status->terminalId)->toBe('00099887')
        ->and($status->code)->toBe('2')
        ->and($status->isOperative())->toBeTrue()
        ->and($status->label())->toBe('Operativo')
        ->and($status->dateTime)->toBe('1708261230')
        ->and($status->softwareRelease)->toBe('01.02.03');
});

it('names a terminal that is not ready to take payments', function () {
    $payload = '00099887'.'0'.'s'.str_repeat('0', 10).'1708261230'.'0'.'01.02.03';

    $status = EcrResponse::terminalStatus($payload);

    expect($status->isOperative())->toBeFalse()
        ->and($status->label())->toBe('Non configurato');
});

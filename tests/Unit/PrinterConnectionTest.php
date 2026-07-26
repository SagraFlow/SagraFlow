<?php

use App\Printing\PrinterConnection;
use App\Printing\PrinterStatusParser;

function connection(): PrinterConnection
{
    return new PrinterConnection(new PrinterStatusParser);
}

it('reads whether offline status reporting is enabled from the memory-switch reply', function (string $response, ?bool $expected) {
    expect(connection()->parseOfflineStatusSetting($response))->toBe($expected);
})->with([
    // Header 0x37, Identifier 0x21, then bit8..bit1 as '0'/'1', then NUL.
    'Msw1-3 off (default)' => ["\x37\x21".'11000000'."\x00", false],
    'Msw1-3 on (configured)' => ["\x37\x21".'00000100'."\x00", true],
    'no reply' => ['', null],
    'unexpected header' => ["\x00\x00".'00000100'."\x00", null],
]);

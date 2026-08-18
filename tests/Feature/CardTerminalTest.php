<?php

use App\CardPayments\EcrConnection;
use App\CardPayments\TerminalProbe;
use App\Exceptions\CardTerminalException;
use App\Exceptions\EcrUnsentException;
use App\Models\CardTerminal;
use App\Models\CashRegister;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes the name on write', function () {
    $terminal = CardTerminal::factory()->create(['name' => '  POS   Cassa 1 ']);

    expect($terminal->name)->toBe('POS Cassa 1');
});

it('scopes to active terminals', function () {
    CardTerminal::factory()->create(['active' => true]);
    CardTerminal::factory()->create(['active' => false]);

    expect(CardTerminal::active()->count())->toBe(1);
});

it('keeps the leading zeros of a terminal id', function () {
    // The id is echoed back in every message of the protocol: read as a number
    // it would go out one character short and the terminal would not answer.
    $terminal = CardTerminal::factory()->create(['terminal_id' => '00123456']);

    expect($terminal->fresh()->terminal_id)->toBe('00123456');
});

it('forbids two terminals on the same ip and port', function () {
    CardTerminal::factory()->create(['ip_address' => '10.0.0.20', 'port' => 6000]);

    CardTerminal::factory()->create(['ip_address' => '10.0.0.20', 'port' => 6000]);
})->throws(UniqueConstraintViolationException::class);

it('forbids two terminals with the same terminal id', function () {
    CardTerminal::factory()->create(['terminal_id' => '12345678']);

    CardTerminal::factory()->create(['terminal_id' => '12345678']);
})->throws(UniqueConstraintViolationException::class);

it('serves the register it is linked to', function () {
    $terminal = CardTerminal::factory()->create();
    $register = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    expect($register->cardTerminal->is($terminal))->toBeTrue()
        ->and($terminal->cashRegisters->contains($register))->toBeTrue();
});

it('serves more than one register', function () {
    // A shared terminal is allowed: what it cannot do is two payments at once,
    // and that is a matter for the moment of payment, not for the schema.
    $terminal = CardTerminal::factory()->create();
    $bar = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $kitchen = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    expect($terminal->cashRegisters)->toHaveCount(2)
        ->and($bar->cardTerminal->is($terminal))->toBeTrue()
        ->and($kitchen->cardTerminal->is($terminal))->toBeTrue();
});

it('is taken by the station that claims it first', function () {
    $terminal = CardTerminal::factory()->create();
    $bar = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $kitchen = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    expect($terminal->claim($bar))->toBeTrue()
        ->and($terminal->isBusy())->toBeTrue()
        ->and($terminal->busyRegisterName())->toBe($bar->name);

    // The second station is told, not queued: it can wait or take cash.
    expect($terminal->fresh()->claim($kitchen))->toBeFalse()
        ->and($terminal->fresh()->claimed_by_cash_register_id)->toBe($bar->id)
        ->and($terminal->fresh()->isAvailableFor($kitchen))->toBeFalse()
        ->and($terminal->fresh()->isAvailableFor($bar))->toBeTrue();
});

it('lets the station holding it claim again, which renews the hold', function () {
    $terminal = CardTerminal::factory()->create();
    $register = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    $terminal->claim($register);
    $firstExpiry = $terminal->claim_expires_at;

    $this->travel(30)->seconds();

    expect($terminal->claim($register))->toBeTrue()
        ->and($terminal->claim_expires_at->greaterThan($firstExpiry))->toBeTrue();
});

it('frees itself when a claim is left to expire', function () {
    $terminal = CardTerminal::factory()->create();
    $bar = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $kitchen = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    $terminal->claim($bar);

    // The tablet that held it went flat: without the expiry the terminal would
    // stay locked for the rest of the evening.
    $this->travel(CardTerminal::CLAIM_TTL_SECONDS + 1)->seconds();

    expect($terminal->isBusy())->toBeFalse()
        ->and($terminal->claim($kitchen))->toBeTrue()
        ->and($terminal->claimed_by_cash_register_id)->toBe($kitchen->id);
});

it('is given back only by the station holding it', function () {
    $terminal = CardTerminal::factory()->create();
    $bar = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $kitchen = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    $terminal->claim($bar);

    // A release arriving late from a payment whose claim already expired must
    // not free a transaction someone else has since started.
    expect($terminal->release($kitchen))->toBeFalse()
        ->and($terminal->isBusy())->toBeTrue();

    expect($terminal->release($bar))->toBeTrue()
        ->and($terminal->isBusy())->toBeFalse()
        ->and($terminal->claimed_by_cash_register_id)->toBeNull()
        ->and($terminal->claim($kitchen))->toBeTrue();
});

it('cannot be deleted while any register uses it', function () {
    $terminal = CardTerminal::factory()->create();
    CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    expect(fn () => $terminal->delete())->toThrow(CardTerminalException::class);
    expect(CardTerminal::whereKey($terminal->id)->exists())->toBeTrue();
});

it('can be deleted when no cash register uses it', function () {
    $terminal = CardTerminal::factory()->create();

    $terminal->delete();

    expect(CardTerminal::whereKey($terminal->id)->exists())->toBeFalse();
});

it('leaves a register without a terminal, which pays by hand', function () {
    // Not every station gets one: two terminals, more registers than that, and
    // the manual card flow stays the way those stations take a card.
    $register = CashRegister::factory()->create();

    expect($register->card_terminal_id)->toBeNull()
        ->and($register->cardTerminal)->toBeNull();
});

/** A terminal that answers a status request with whatever is given. */
function probeAnswering(Closure $answer): TerminalProbe
{
    return new TerminalProbe(new class($answer) extends EcrConnection
    {
        public function __construct(private readonly Closure $answer)
        {
            parent::__construct();
        }

        public function request(string $host, int $port, string $payload, int $connectTimeout = 5, int $readTimeout = 180, ?Closure $onProgress = null, int $attempts = self::MAX_ATTEMPTS): string
        {
            return ($this->answer)($payload);
        }
    });
}

it('reads back the state a terminal reports', function () {
    $terminal = CardTerminal::factory()->create(['terminal_id' => '00099887']);

    $result = probeAnswering(fn () => '00099887'.'0'.'s'.str_repeat('0', 10).'1708262348'.'2'.'SYSEMV')
        ->probe($terminal);

    expect($result['status']->isOperative())->toBeTrue()
        ->and($result['status']->softwareRelease)->toBe('SYSEMV')
        ->and($result['error'])->toBeNull();
});

it('reports a terminal that does not answer, rather than throwing', function () {
    $terminal = CardTerminal::factory()->create();

    $result = probeAnswering(fn () => throw new EcrUnsentException('Connessione fallita.'))->probe($terminal);

    expect($result['status'])->toBeNull()
        ->and($result['error'])->toBe('Connessione fallita.');
});

it('will not question a terminal that is taking a payment', function () {
    $terminal = CardTerminal::factory()->create();
    $register = CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($register);

    // It holds one conversation at a time: asking now would at best be refused,
    // at worst talk over a customer typing their PIN.
    $result = probeAnswering(fn () => throw new RuntimeException('non deve essere chiamato'))->probe($terminal);

    expect($result['busyWith'])->toBe('Cassa Bar')
        ->and($result['status'])->toBeNull()
        ->and($result['error'])->toBeNull();
});

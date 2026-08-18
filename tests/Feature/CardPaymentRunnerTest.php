<?php

use App\CardPayments\EcrConnection;
use App\CardPayments\PaymentRunner;
use App\Enums\CardPaymentOutcome;
use App\Enums\CardTransactionStatus;
use App\Exceptions\EcrProtocolException;
use App\Exceptions\EcrUnsentException;
use App\Models\CardTerminal;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use App\Settings\EventSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A terminal that says whatever the test wants, without a socket in sight. */
class FakeEcrConnection extends EcrConnection
{
    /** @var array<int, string> */
    public array $sent = [];

    public function __construct(private readonly Closure $answer)
    {
        parent::__construct();
    }

    public function request(
        string $host,
        int $port,
        string $payload,
        int $connectTimeout = 5,
        int $readTimeout = 180,
        ?Closure $onProgress = null,
        int $attempts = self::MAX_ATTEMPTS,
    ): string {
        $this->sent[] = $payload;

        return ($this->answer)($payload, $onProgress);
    }
}

/** An extended payment result, laid out as the terminal lays it out. */
function cardResult(int $amountCents, string $stan = '000103', string $result = '00', string $description = 'CARTA RIFIUTATA', ?string $when = null): string
{
    $payload = '00099887'.'0'.'E'.$result;

    $payload .= $result === '00'
        ? str_pad('4321', 19, '0', STR_PAD_LEFT).'CLI'.str_pad('UWD0AF', 6).($when ?? hostDateTime())
        : str_pad($description, 24).str_repeat('0', 11);

    return $payload
        .'2'
        .str_pad('00000000034', 11)
        .str_pad($stan, 6, '0', STR_PAD_LEFT)
        .'000000'
        .'000'
        .str_pad((string) $amountCents, 8, '0', STR_PAD_LEFT)
        .str_repeat('0', 10);
}

/** Minutes between the event's own clock and ours, as the host would write. */
function eventClockOffset(): int
{
    return CarbonImmutable::now(app(EventSettings::class)->timezone)->utcOffset();
}

function runnerFor(Closure $answer): PaymentRunner
{
    return new PaymentRunner(new FakeEcrConnection($answer));
}

function registerWithTerminal(): array
{
    $terminal = CardTerminal::factory()->create(['terminal_id' => '00099887']);
    $register = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);

    return [$register, $terminal];
}

it('takes the terminal and writes the attempt before asking for anything', function () {
    [$register, $terminal] = registerWithTerminal();

    $attempt = runnerFor(fn () => cardResult(1250))->start($register, 1250);

    expect($attempt)->not->toBeNull()
        ->and($attempt->status)->toBe(CardTransactionStatus::Pending)
        ->and($attempt->amount_cents)->toBe(1250)
        ->and($attempt->terminal_id)->toBe('00099887')
        ->and($attempt->ecr_id)->toBe(str_pad((string) $register->id, 8, '0', STR_PAD_LEFT))
        ->and($terminal->fresh()->isBusy())->toBeTrue();
});

it('does not start when another station is on the terminal', function () {
    [$register, $terminal] = registerWithTerminal();
    $other = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $terminal->claim($other);

    $attempt = runnerFor(fn () => cardResult(500))->start($register, 500);

    expect($attempt)->toBeNull()
        ->and(CardTransaction::count())->toBe(0);
});

it('does not start on a station with no terminal', function () {
    $register = CashRegister::factory()->create();

    expect(runnerFor(fn () => cardResult(500))->start($register, 500))->toBeNull();
});

it('records an approved payment and gives the terminal back', function () {
    [$register, $terminal] = registerWithTerminal();
    $runner = runnerFor(fn () => cardResult(1250));

    $attempt = $runner->run($runner->start($register, 1250));

    expect($attempt->status)->toBe(CardTransactionStatus::Approved)
        ->and($attempt->outcome)->toBe(CardPaymentOutcome::Approved)
        ->and($attempt->amount_from_host_cents)->toBe(1250)
        ->and($attempt->authorization_code)->toBe('UWD0AF')
        ->and($attempt->stan)->toBe('000103')
        ->and($attempt->pan_last4)->toBe('4321')
        ->and($attempt->completed_at)->not->toBeNull()
        ->and($terminal->fresh()->isBusy())->toBeFalse();
});

it('records a refusal with the reason, and frees the terminal', function () {
    [$register, $terminal] = registerWithTerminal();
    $runner = runnerFor(fn () => cardResult(1250, result: '01', description: 'FONDI INSUFFICIENTI'));

    $attempt = $runner->run($runner->start($register, 1250));

    expect($attempt->status)->toBe(CardTransactionStatus::Declined)
        ->and($attempt->reason())->toBe('FONDI INSUFFICIENTI')
        ->and($attempt->isApproved())->toBeFalse()
        ->and($terminal->fresh()->isBusy())->toBeFalse();
});

it('shows what the terminal is asking the customer while it waits', function () {
    [$register] = registerWithTerminal();
    $runner = runnerFor(function (string $payload, ?Closure $onProgress) {
        $onProgress('INSERIRE CARTA');
        $onProgress('DIGITARE PIN');

        return cardResult(300);
    });

    $attempt = $runner->start($register, 300);
    $seen = [];
    CardTransaction::saved(function (CardTransaction $saved) use (&$seen): void {
        $seen[] = $saved->progress;
    });

    $attempt = $runner->run($attempt);

    expect($seen)->toContain('INSERIRE CARTA', 'DIGITARE PIN')
        ->and($attempt->progress)->toBeNull(); // cleared once it is over
});

it('calls a message that never left a failure, not a doubt', function () {
    [$register, $terminal] = registerWithTerminal();
    $runner = runnerFor(fn () => throw new EcrUnsentException('Connessione fallita.'));

    $attempt = $runner->run($runner->start($register, 1250));

    // Nothing was processed, so nothing was charged: the station can try again
    // or take cash, and the terminal goes back to being free.
    expect($attempt->status)->toBe(CardTransactionStatus::Failed)
        ->and($attempt->reason())->toBe('Connessione fallita.')
        ->and($terminal->fresh()->isBusy())->toBeFalse();
});

it('keeps hold of the terminal when the answer never came', function () {
    [$register, $terminal] = registerWithTerminal();
    $runner = runnerFor(fn () => throw new EcrProtocolException('Il terminale non ha risposto entro il tempo massimo.'));

    $attempt = $runner->run($runner->start($register, 1250));

    // The customer may well have paid. Asking the terminal later what it last
    // did only means something while no other station has started anything.
    expect($attempt->status)->toBe(CardTransactionStatus::Unknown)
        ->and($attempt->needsAnswer())->toBeTrue()
        ->and($terminal->fresh()->isBusy())->toBeTrue();
});

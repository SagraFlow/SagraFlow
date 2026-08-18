<?php

use App\Enums\CardTransactionStatus;
use App\Filament\Resources\CardTransactions\CardTransactionResource;
use App\Filament\Resources\CardTransactions\Pages\ListCardTransactions;
use App\Models\CardTerminal;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/** An attempt left open, with the terminal still held by its station. */
function heldAttempt(CardTransactionStatus $status, int $amountCents = 1250, ?int $ageSeconds = null): CardTransaction
{
    $terminal = CardTerminal::factory()->create(['terminal_id' => '00099887']);
    $register = CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $terminal->claim($register);

    $attempt = CardTransaction::factory()->create([
        'card_terminal_id' => $terminal->id,
        'cash_register_id' => $register->id,
        'terminal_id' => '00099887',
        'amount_cents' => $amountCents,
        'status' => $status,
    ]);

    if ($ageSeconds !== null) {
        $attempt->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();
    }

    return $attempt->fresh();
}

it('leaves a payment that is genuinely under way alone', function () {
    $attempt = heldAttempt(CardTransactionStatus::Pending, ageSeconds: 20);

    $this->artisan('card:reconcile')->assertSuccessful();

    // The customer is on the terminal: nothing to reconcile yet.
    expect($attempt->fresh()->status)->toBe(CardTransactionStatus::Pending);
});

it('turns an attempt nobody is coming back to into the question it is', function () {
    $attempt = heldAttempt(CardTransactionStatus::Pending, ageSeconds: CardTransaction::STUCK_AFTER_SECONDS + 10);

    $this->artisan('card:reconcile')->assertSuccessful();

    // The job never ran, or died without saying so. The money may well have
    // moved, and the answer is on the terminal - not in here.
    expect($attempt->fresh()->status)->toBe(CardTransactionStatus::Unknown)
        ->and($attempt->fresh()->reason())->toContain('controlla il terminale');
});

it('never decides by itself whether a payment went through', function () {
    $attempt = heldAttempt(CardTransactionStatus::Unknown);

    $this->artisan('card:reconcile')->assertSuccessful();

    // Whoever is holding the terminal reads its screen: this command only makes
    // sure the question reaches them.
    expect($attempt->fresh()->status)->toBe(CardTransactionStatus::Unknown);
});

it('reports money taken with no order behind it', function () {
    Log::spy();

    $attempt = CardTransaction::factory()->approved()->create([
        'order_id' => null,
        'completed_at' => now()->subMinutes(5),
    ]);

    $this->artisan('card:reconcile')->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'senza ordine')
            && $context['card_transaction_id'] === $attempt->id);
});

it('does not report an approval that has only just arrived', function () {
    Log::spy();

    CardTransaction::factory()->approved()->create(['order_id' => null, 'completed_at' => now()]);

    // The till is about to place the order: shouting now would be noise.
    $this->artisan('card:reconcile')->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
});

it('lists the payments to check, and counts them where they will be seen', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $unknown = CardTransaction::factory()->unknown()->create(['amount_cents' => 1250]);
    $unknown->terminal->update(['name' => 'POS Cassa 1']);
    $orphan = CardTransaction::factory()->approved()->create(['order_id' => null]);
    $fine = CardTransaction::factory()->approved()->create([
        'order_id' => Order::factory()->create()->id,
    ]);

    Livewire::test(ListCardTransactions::class)
        ->assertCanSeeTableRecords([$unknown, $orphan, $fine])
        // Named the way people name it, not by the eight digits Nexi knows.
        ->assertSee('POS Cassa 1')
        ->filterTable('needsAttention')
        ->assertCanSeeTableRecords([$unknown, $orphan])
        ->assertCanNotSeeTableRecords([$fine]);

    expect(CardTransactionResource::getNavigationBadge())->toBe('2');
});

<?php

use App\Enums\CardTransactionStatus;
use App\Jobs\SendCardPaymentJob;
use App\Models\CardTerminal;
use App\Models\CardTransaction;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    EventDay::factory()->create()->open(auth()->user());
});

/** An approved extended result for the given amount, as the terminal sends it. */
function terminalApproved(int $amountCents, string $stan): string
{
    return '00099887'.'0'.'E'.'00'
        .str_pad('4321', 19, '0', STR_PAD_LEFT).'CLI'.str_pad('UWD0AF', 6).hostDateTime()
        .'2'.str_pad('00000000034', 11).str_pad($stan, 6, '0', STR_PAD_LEFT).'000000'.'000'
        .str_pad((string) $amountCents, 8, '0', STR_PAD_LEFT).str_repeat('0', 10);
}

/** A till with a food in the cart, ready to be paid. */
function tillWithCart(?CardTerminal $terminal = null): array
{
    $register = CashRegister::factory()->create(['card_terminal_id' => $terminal?->id]);
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 1250]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    return [$component, $register];
}

it('sends the amount to the terminal and waits, without asking the cashier anything', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    $component->call('startCard')
        ->assertSet('showCardModal', true)
        ->assertSee('In attesa del terminale');

    $attempt = CardTransaction::sole();

    expect($attempt->amount_cents)->toBe(1250)
        ->and($attempt->status)->toBe(CardTransactionStatus::Pending)
        ->and($terminal->fresh()->isBusy())->toBeTrue()
        ->and(Order::count())->toBe(0);

    Queue::assertPushed(SendCardPaymentJob::class);
});

it('closes the sale on its own when the terminal approves', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->update(['status' => CardTransactionStatus::Approved, 'stan' => '000103']);

    // The cashier has nothing left to confirm: asking her to would only add a
    // way to get it wrong.
    $component->call('pollCardPayment')
        ->assertSet('showCardModal', false)
        ->assertSet('cart', []);

    $order = Order::sole();

    expect($order->payment_method->value)->toBe('card')
        ->and(CardTransaction::sole()->order_id)->toBe($order->id);
});

it('offers to try again after a refusal, and does not place an order', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->update([
        'status' => CardTransactionStatus::Declined,
        'description' => 'FONDI INSUFFICIENTI',
        'completed_at' => now(),
    ]);

    $component->call('pollCardPayment')
        ->assertSet('showCardModal', true)
        ->assertSee('FONDI INSUFFICIENTI')
        ->assertSee('Riprova sul terminale');

    expect(Order::count())->toBe(0);
});

it('will not send again while an attempt has no answer', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->settleAs(CardTransactionStatus::Unknown, 'Nessuna risposta.');

    $component->call('pollCardPayment')
        ->assertSee('Esito sconosciuto')
        // She is holding the terminal: its screen is the answer, and pressing
        // Carta again before reading it is how a customer gets charged twice.
        ->assertSee('Guarda il terminale')
        ->assertSee('Pagamento riuscito')
        ->call('retryCardPayment');

    // Sending the same amount again could charge the customer twice.
    expect(CardTransaction::count())->toBe(1);
});

it('keeps the terminal held while an attempt has no answer', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component, $register] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->settleAs(CardTransactionStatus::Unknown, 'Nessuna risposta.');

    $component->call('closeCard');

    // Nobody else may start a transaction there until someone finds out what
    // happened: the terminal only remembers its last one.
    expect($terminal->fresh()->isBusy())->toBeTrue()
        ->and($terminal->fresh()->claimed_by_cash_register_id)->toBe($register->id);
});

it('lets the cashier close the sale by hand, and says so on the row', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->settleAs(CardTransactionStatus::Unknown, 'Nessuna risposta.');

    // She is the one looking at the terminal. A row closed by a person must
    // always be tellable from one closed by the terminal.
    $component->call('pollCardPayment')->call('confirmCard');

    $attempt = CardTransaction::sole();

    expect(Order::count())->toBe(1)
        ->and($attempt->manual)->toBeTrue()
        ->and($attempt->status)->toBe(CardTransactionStatus::Approved);
});

it('says which station has the terminal, and offers no confirmation to make', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    $other = CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($other);

    [$component] = tillWithCart($terminal);

    $component->call('startCard')
        ->assertSet('showCardModal', true)
        ->assertSet('terminalBusyWith', 'Cassa Bar')
        ->assertSee('Terminale occupato')
        ->assertSee('Cassa Bar')
        // The other station's customer is on that terminal: there is nothing
        // here for this cashier to have seen, so nothing for her to confirm.
        ->assertDontSee('Pagamento riuscito')
        ->assertSee('Riprova sul terminale');

    expect(CardTransaction::count())->toBe(0);
});

it('refuses a manual confirmation while the terminal belongs to another station', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    $other = CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($other);

    [$component] = tillWithCart($terminal);

    // Even asked for directly: confirming here would close a sale on the back
    // of a payment being taken at another till.
    $component->call('startCard')->call('confirmCard');

    expect(Order::count())->toBe(0);
});

it('announces a freed terminal without sending anything to it', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    $other = CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($other);

    [$component] = tillWithCart($terminal);
    $component->call('startCard')->assertSet('terminalBusyWith', 'Cassa Bar');

    $terminal->release($other);

    // A POS that has just finished is still in the other cashier's hands: the
    // customer is taking their card back. Nothing is sent until this cashier
    // has the terminal in front of her and says so.
    $component->call('watchTerminal')
        ->assertSet('terminalFreeAgain', true)
        ->assertDispatched('pos-notice')
        ->assertSee('Terminale libero')
        ->assertSee('premi Riprova');

    expect(CardTransaction::count())->toBe(0);

    $component->call('retryCardPayment')
        ->assertSet('terminalFreeAgain', false)
        ->assertSee('In attesa del terminale');

    expect(CardTransaction::count())->toBe(1);
});

it('goes back to occupied if another station takes the terminal first', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    $bar = CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($bar);

    [$component] = tillWithCart($terminal);
    $component->call('startCard')->assertSet('terminalBusyWith', 'Cassa Bar');

    $terminal->release($bar);
    $component->call('watchTerminal')->assertSet('terminalFreeAgain', true);

    // Someone was quicker: the modal has to say what is true now, not what it
    // announced a moment ago.
    $kitchen = CashRegister::factory()->create(['name' => 'Cassa Cucina', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($kitchen);

    $component->call('watchTerminal')
        ->assertSet('terminalFreeAgain', false)
        ->assertSet('terminalBusyWith', 'Cassa Cucina');
});

it('goes on waiting while the terminal is still someone else\'s', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    $other = CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);
    $terminal->claim($other);

    [$component] = tillWithCart($terminal);

    $component->call('startCard')
        ->call('watchTerminal')
        ->assertSet('terminalBusyWith', 'Cassa Bar');

    expect(CardTransaction::count())->toBe(0);
});

it('keeps the old manual flow on a station without a terminal', function () {
    Queue::fake();
    [$component] = tillWithCart();

    $component->call('startCard')
        ->assertSee('La transazione è andata a buon fine?')
        ->call('confirmCard');

    expect(Order::count())->toBe(1)
        ->and(CardTransaction::count())->toBe(0);
});

it('warns before charging a card again while one has no answer', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component, $register] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->settleAs(CardTransactionStatus::Unknown, 'Nessuna risposta.');
    $component->call('cardToCash')->call('closeCash');

    // Nothing stops the claim here - the terminal was taken by this very
    // station, so a second "Carta" would just renew it and send another amount
    // for a payment that may already have gone through.
    $component->call('startCard')
        ->assertSee('C\'è un pagamento senza esito', escape: false)
        ->assertSee('controlla il terminale')
        ->assertDontSee('In attesa del terminale');

    expect(CardTransaction::count())->toBe(1);
});

it('sends the amount once the cashier says she has looked', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    $component->call('startCard');
    CardTransaction::sole()->settleAs(CardTransactionStatus::Unknown, 'Nessuna risposta.');
    $component->call('closeCard')->call('startCard');

    $component->call('acknowledgeUnresolved')
        ->assertSet('unresolvedPayment', null)
        ->assertSee('In attesa del terminale');

    expect(CardTransaction::count())->toBe(2);
});

it('refuses a manual confirmation over a payment still under way', function () {
    Queue::fake();
    $terminal = CardTerminal::factory()->create();
    [$component] = tillWithCart($terminal);

    // A stale page in her hand must not be able to close a sale over a customer
    // who is still typing their PIN.
    $component->call('startCard')->call('confirmCard');

    expect(Order::count())->toBe(0)
        ->and(CardTransaction::sole()->status)->toBe(CardTransactionStatus::Pending);
});

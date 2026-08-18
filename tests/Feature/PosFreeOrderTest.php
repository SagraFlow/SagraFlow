<?php

use App\Enums\PaymentMethod;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    EventDay::factory()->create()->open(auth()->user());
});

/** A cart whose total a discount has taken to zero. */
function fullyDiscountedTill(): object
{
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 1250]);

    return Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('discountType', 'percentage')
        ->set('discountValue', '100')
        ->call('applyDiscount');
}

it('offers one button to confirm when there is nothing to take', function () {
    $component = fullyDiscountedTill();

    // Two tender buttons would both be a lie, and the cash one would kick the
    // drawer open for no money.
    $component->assertSee('Conferma ordine')
        ->assertDontSee('Contanti')
        ->assertDontSee('Carta');
});

it('asks before letting an order leave the till with no money against it', function () {
    $component = fullyDiscountedTill();

    $component->call('startFreeOrder')
        ->assertSet('showFreeOrder', true)
        ->assertSee('Ordine senza incasso');

    expect(Order::count())->toBe(0);
});

it('places the order as a giveaway, which is neither cash nor card', function () {
    $component = fullyDiscountedTill();

    $component->call('startFreeOrder')->call('confirmFreeOrder')
        ->assertSet('showFreeOrder', false)
        ->assertSet('cart', []);

    $order = Order::sole();

    expect($order->payment_method)->toBe(PaymentMethod::None)
        ->and($order->payment_method->getLabel())->toBe('Omaggio')
        ->and($order->total)->toBe(0);
});

it('gives the held stock back when the confirmation is dropped', function () {
    $component = fullyDiscountedTill();

    $component->call('startFreeOrder')
        ->call('closeFreeOrder')
        ->assertSet('showFreeOrder', false)
        ->assertSet('reservationId', null);

    expect(Order::count())->toBe(0);
});

it('refuses to open a payment for nothing', function () {
    $component = fullyDiscountedTill();

    // Called directly, as a stale page would: a card payment of zero is refused
    // by the protocol itself, and would come back looking like a doubt.
    $component->call('startCard')->assertSet('showCardModal', false)
        ->call('startCash')->assertSet('showCashModal', false);
});

it('leaves the two tender buttons alone when there is something to take', function () {
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id, 'price' => 1250]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->assertSee('Contanti')
        ->assertSee('Carta')
        ->assertDontSee('Conferma ordine')
        ->call('startFreeOrder')
        ->assertSet('showFreeOrder', false);
});

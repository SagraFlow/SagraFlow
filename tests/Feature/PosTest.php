<?php

use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Settings\EventSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function openDay(): EventDay
{
    $day = EventDay::factory()->create();
    $day->open(auth()->user());

    return $day;
}

it('tells the operator when no day is open', function () {
    Livewire::test('pages::pos')->assertSee('Nessuna giornata aperta');
});

it('asks to pick a register when a day is open', function () {
    openDay();
    CashRegister::factory()->create(['name' => 'Cassa 1']);

    Livewire::test('pages::pos')
        ->assertSee('Seleziona la cassa')
        ->assertSee('Cassa 1');
});

it('enters the pos shell once a register is selected', function () {
    openDay();
    $register = CashRegister::factory()->create(['name' => 'Cassa 2']);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Cassa 2')
        ->assertSee('Totale')
        ->assertSee('Carrello vuoto');
});

it('remembers the selected register from the session', function () {
    openDay();
    $register = CashRegister::factory()->create(['name' => 'Cassa 3']);
    session(['pos_cash_register_id' => $register->id]);

    Livewire::test('pages::pos')
        ->assertSee('Cassa 3')
        ->assertDontSee('Seleziona la cassa');
});

it('adds foods to the cart and totals them', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee($food->name)
        ->call('addFood', $food->id)
        ->call('addFood', $food->id)
        ->assertSee('€ 10,00');
});

it('decrements and removes a cart line', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('addFood', $food->id);

    $key = array_key_first($component->get('cart'));
    expect($component->get('cart')[$key]['quantity'])->toBe(2);

    $component->call('decrementLine', $key);
    expect($component->get('cart')[$key]['quantity'])->toBe(1);

    $component->call('decrementLine', $key);
    expect($component->get('cart'))->toBeEmpty();
});

it('adds a product directly to the cart without prompting for customizations', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Pane e salamina']);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => 200]);
    $food->ingredients()->attach($salamina->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    expect($component->get('cart'))->toHaveCount(1)
        ->and($component->get('customizingFoodId'))->toBeNull();
});

it('edits a cart line to add the extra surcharge', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 400]);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => 200]);
    $food->ingredients()->attach($salamina->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $key = array_key_first($component->get('cart'));

    $component->call('editLine', $key)
        ->call('incIngredient', $salamina->id)
        ->call('confirmCustomize')
        ->assertSee('€ 6,00')
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    expect($order->total)->toBe(600)
        ->and($order->lines->first()->ingredients->firstWhere('ingredient_name', 'Salamina')->quantity)->toBe(2);
});

it('does not let an ingredient exceed its max when editing', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 400]);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => 200]);
    $food->ingredients()->attach($salamina->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $key = array_key_first($component->get('cart'));

    $component->call('editLine', $key)
        ->call('incIngredient', $salamina->id)
        ->call('incIngredient', $salamina->id)
        ->call('confirmCustomize')
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash');

    expect(Order::first()->total)->toBe(600);
});

it('keeps an edited line in its original position', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $foodA = Food::factory()->create(['category_id' => $category->id, 'name' => 'Alpha']);
    $foodB = Food::factory()->create(['category_id' => $category->id, 'name' => 'Beta']);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => 200]);
    $foodA->ingredients()->attach($salamina->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $foodA->id)
        ->call('addFood', $foodB->id);

    $keyA = array_key_first($component->get('cart'));

    $component->call('editLine', $keyA)
        ->call('incIngredient', $salamina->id)
        ->call('confirmCustomize');

    // Food A was edited but must remain the first line, not pushed to the end.
    expect(array_values($component->get('cart'))[0]['food_id'])->toBe($foodA->id);
});

it('keeps different customizations as separate cart lines', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 400]);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => 200]);
    $food->ingredients()->attach($salamina->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $key = array_key_first($component->get('cart'));

    $component->call('editLine', $key)
        ->call('incIngredient', $salamina->id)
        ->call('confirmCustomize') // this line becomes 2 salamina
        ->call('addFood', $food->id); // new default line (1 salamina)

    expect($component->get('cart'))->toHaveCount(2);
});

it('records the number of covers on the order', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('tableInput', '3')
        ->call('chooseTable')
        ->call('incCovers')
        ->call('incCovers')
        ->call('incCovers')
        ->call('incCovers')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    expect(Order::first()->covers)->toBe(4);
});

it('adds the cover charge to the order total', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $settings = app(EventSettings::class);
    $settings->coverCharge = 150;
    $settings->save();

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('tableInput', '3')
        ->call('chooseTable')
        ->call('incCovers')
        ->call('incCovers')
        ->assertSee('Coperti')
        ->assertSee('€ 8,00') // 5,00 goods + 2 x 1,50 coperto
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    expect($order->total)->toBe(800)
        ->and($order->covers)->toBe(2)
        ->and($order->cover_charge)->toBe(150);
});

it('discounts the cover charge when the setting is enabled', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 1000]);

    $settings = app(EventSettings::class);
    $settings->coverCharge = 200;
    $settings->discountAppliesToCover = true;
    $settings->save();

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('tableInput', '1')
        ->call('chooseTable')
        ->call('incCovers') // 1 cover → 2,00 coperto
        ->set('discountType', 'percentage')
        ->set('discountValue', 10)
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    // base 1000 + 200 = 1200; -10% = 120; total 1080
    expect($order->total)->toBe(1080)
        ->and($order->discount_amount)->toBe(120);
});

it('does not add a cover charge when none is configured', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('incCovers')
        ->assertDontSee('Coperti (')
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    expect(Order::first()->total)->toBe(500);
});

it('charges the price frozen when the item was added, not the current one', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id); // freezes 5,00

    // Admin reprices the food mid-sale.
    $food->update(['price' => 900]);

    $component->call('choosePickup');
    $component->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    expect($order->total)->toBe(500)
        ->and($order->lines->first()->unit_price)->toBe(500);
});

it('freezes the cover charge from when the sale started', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $settings = app(EventSettings::class);
    $settings->coverCharge = 100;
    $settings->save();

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id) // freezes coperto at 1,00
        ->set('tableInput', '1')
        ->call('chooseTable')
        ->call('incCovers');

    // Admin changes the coperto mid-sale.
    $settings->coverCharge = 300;
    $settings->save();

    $component->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    expect($order->cover_charge)->toBe(100) // sale-start value, not 300
        ->and($order->total)->toBe(600);    // 500 + 1 x 100
});

it('freezes the discount-on-cover choice from when the sale started', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 1000]);

    $settings = app(EventSettings::class);
    $settings->coverCharge = 200;
    $settings->discountAppliesToCover = true;
    $settings->save();

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id) // freezes flag = true, coperto 2,00
        ->set('tableInput', '1')
        ->call('chooseTable')
        ->call('incCovers')
        ->set('discountType', 'percentage')
        ->set('discountValue', 10);

    // Admin turns the flag off mid-sale.
    $settings->discountAppliesToCover = false;
    $settings->save();

    $component->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    // Frozen flag = true → base 1000 + 200 = 1200; -10% = 120; total 1080
    expect($order->discount_applies_to_cover)->toBeTrue()
        ->and($order->total)->toBe(1080);
});

it('blocks checkout when the day has been closed mid-sale', function () {
    $day = openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $day->close(auth()->user());

    $component->call('choosePickup');
    $component->call('startCash')
        ->assertHasErrors('checkout')
        ->assertSet('showCashModal', false);

    expect(Order::count())->toBe(0);
});

it('blocks checkout when the selected register was deactivated mid-sale', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $register->update(['active' => false]);

    $component->call('choosePickup');
    $component->call('startCard')
        ->assertHasErrors('checkout')
        ->assertSet('showCardModal', false);

    expect(Order::count())->toBe(0);
});

it('logs the operator out', function () {
    openDay();
    $register = CashRegister::factory()->create();
    session(['pos_cash_register_id' => $register->id]);

    Livewire::test('pages::pos')
        ->call('logout')
        ->assertRedirect(route('filament.admin.auth.login'));

    expect(auth()->check())->toBeFalse();
});

it('clears the cart and the order details', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('tableInput', '5')
        ->call('chooseTable')
        ->call('openClearCart')
        ->assertSet('showClearCart', true)
        ->call('clearCart');

    expect($component->get('cart'))->toBeEmpty()
        ->and($component->get('serviceType'))->toBeNull()
        ->and($component->get('tableNumber'))->toBeNull();

    $component->assertSet('showClearCart', false);
});

it('reverts the discount when cancelled', function () {
    $component = Livewire::test('pages::pos')
        ->set('discountType', 'fixed')
        ->set('discountValue', '5')
        ->call('openDiscount')
        ->set('discountType', 'percentage')
        ->set('discountValue', '50')
        ->call('cancelDiscount');

    expect($component->get('discountType'))->toBe('fixed')
        ->and($component->get('discountValue'))->toBe('5');

    $component->assertSet('showDiscount', false);
});

it('does not let covers go below zero', function () {
    Livewire::test('pages::pos')
        ->assertSet('covers', 0)
        ->call('decCovers')
        ->assertSet('covers', 0);
});

it('records a per-line note from the modal', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $key = array_key_first($component->get('cart'));

    $component->call('editLine', $key)
        ->set('customizeNote', 'senza glutine')
        ->call('confirmCustomize')
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    expect(Order::first()->lines->first()->note)->toBe('senza glutine');
});

it('hides foods not sellable on the open day', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $onlyOtherDay = Food::factory()->create(['category_id' => $category->id, 'name' => 'Solo altro giorno']);
    $otherDay = EventDay::factory()->create(['date' => '2030-01-01']);
    $onlyOtherDay->eventDays()->attach($otherDay->id);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertDontSee('Solo altro giorno');
});

it('places a table order from checkout and confirms with the number', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('addFood', $food->id)
        ->set('tableInput', '7')
        ->call('chooseTable')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors()
        ->assertSet('cart', [])
        ->assertSee('#1');

    $order = Order::first();

    expect($order->number)->toBe(1)
        ->and($order->service_type)->toBe(ServiceType::TableService)
        ->and($order->table_number)->toBe(7)
        ->and($order->payment_method)->toBe(PaymentMethod::Cash)
        ->and($order->total)->toBe(1000)
        ->and($order->lines)->toHaveCount(1);
});

it('places a pickup order when the pickup key is pressed', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard')
        ->call('confirmCard')
        ->assertHasNoErrors();

    expect(Order::first()->service_type)->toBe(ServiceType::Pickup)
        ->and(Order::first()->table_number)->toBeNull();
});

/**
 * A till with one item in the cart and no service choice made yet.
 */
function tillAwaitingServiceChoice(): object
{
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    return Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);
}

it('opens the choice instead of taking money when nobody said where the order goes', function (string $payment) {
    tillAwaitingServiceChoice()
        ->call($payment)
        ->assertSet('showService', true)
        ->assertSet('showCashModal', false)
        ->assertSet('showCardModal', false)
        ->assertSet('reservationId', null);

    expect(Order::count())->toBe(0);
})->with(['startCash', 'startCard']);

it('opens the choice instead of confirming a giveaway with no destination', function () {
    tillAwaitingServiceChoice()
        ->set('discountType', 'percentage')
        ->set('discountValue', '100')
        ->call('applyDiscount')
        ->call('startFreeOrder')
        ->assertSet('showService', true)
        ->assertSet('showFreeOrder', false);

    expect(Order::count())->toBe(0);
});

it('refuses to place the order even if the payment is forced through without a choice', function () {
    // Straight to the confirmation, skipping the screen that opens the choice.
    tillAwaitingServiceChoice()
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasErrors('checkout');

    expect(Order::count())->toBe(0);
});

it('builds the table number one key at a time', function () {
    tillAwaitingServiceChoice()
        ->call('openService')
        ->call('pressTableDigit', 0)   // a table 0 does not exist: ignored
        ->call('pressTableDigit', 1)
        ->call('pressTableDigit', 0)   // but a zero after a digit is a ten
        ->call('pressTableDigit', 4)
        ->assertSet('tableInput', '104')
        ->call('backspaceTable')
        ->assertSet('tableInput', '10')
        ->call('clearTable')
        ->assertSet('tableInput', '');
});

it('stops the table number at four digits, which is what the field holds', function () {
    tillAwaitingServiceChoice()
        ->call('openService')
        ->call('pressTableDigit', 1)
        ->call('pressTableDigit', 2)
        ->call('pressTableDigit', 3)
        ->call('pressTableDigit', 4)
        ->call('pressTableDigit', 5)
        ->assertSet('tableInput', '1234');
});

it('takes the typed number as the table, and needs a number to take', function () {
    $component = tillAwaitingServiceChoice()
        ->call('openService')
        ->call('chooseTable')          // nothing typed: nothing chosen
        ->assertSet('showService', true)
        ->assertSet('serviceType', null)
        ->set('tableInput', '12')
        ->call('chooseTable')
        ->assertSet('showService', false)
        ->assertSet('serviceType', 'table_service')
        ->assertSet('tableNumber', 12);

    $component->assertSee('Tavolo 12');
});

it('drops the table when the order becomes a pickup', function () {
    tillAwaitingServiceChoice()
        ->set('tableInput', '12')
        ->call('chooseTable')
        ->call('choosePickup')
        ->assertSet('serviceType', 'pickup')
        ->assertSet('tableNumber', null)
        ->assertSee('Ritiro');
});

it('offers the chosen table back for correction, and starts empty on a pickup', function () {
    $component = tillAwaitingServiceChoice()
        ->set('tableInput', '12')
        ->call('chooseTable')
        ->call('openService')
        ->assertSet('tableInput', '12');

    $component->call('choosePickup')
        ->call('openService')
        ->assertSet('tableInput', '');
});

it('asks again for the next order once one is placed', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('tableInput', '7')
        ->call('chooseTable')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors()
        ->assertSet('serviceType', null)
        ->assertSet('tableNumber', null)
        ->assertSee('Da scegliere');
});

it('applies a fixed discount entered in euros at checkout', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 1000]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('discountType', 'fixed')
        ->set('discountValue', 2)
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    $order = Order::first();

    expect($order->subtotal)->toBe(1000)
        ->and($order->discount_amount)->toBe(200)
        ->and($order->total)->toBe(800);
});

it('applies a percentage discount at checkout', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 1000]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->set('discountType', 'percentage')
        ->set('discountValue', 10)
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    expect(Order::first()->total)->toBe(900);
});

it('does not confirm cash payment when the amount is insufficient', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 1000]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash')
        ->call('addCash', 500) // only 5€ for a 10€ order
        ->call('confirmCash');

    expect(Order::count())->toBe(0);
});

it('computes the change for a cash payment', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 1200]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash')
        ->call('addCash', 2000);

    expect($component->get('cashReceivedCents'))->toBe(2000);
    // 20€ received - 12€ total = 8€ change
    $component->call('confirmCash')->assertHasNoErrors();

    expect(Order::first()->total)->toBe(1200);
});

it('parses the typed cash amount into cents', function () {
    $component = Livewire::test('pages::pos')
        ->set('cashInput', '12,50');

    expect($component->get('cashReceivedCents'))->toBe(1250);
});

it('does not place a second order when the payment is confirmed twice', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCash')
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    // A duplicate confirm (e.g. an accidental double click) must be a no-op:
    // the cart has already been cleared by the first, successful checkout.
    $component->call('confirmCash');

    expect(Order::count())->toBe(1);
});

it('cancels a card payment without creating the order', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard')
        ->call('closeCard');

    expect(Order::count())->toBe(0);
});

it('falls back from card to cash payment', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard')
        ->call('cardToCash')
        ->assertSet('showCardModal', false)
        ->assertSet('showCashModal', true)
        ->call('setExactCash')
        ->call('confirmCash')
        ->assertHasNoErrors();

    expect(Order::first()->payment_method->value)->toBe('cash');
});

it('shows a food as sold out and blocks adding it when a tracked ingredient is exhausted', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'name' => 'Panino']);
    $salsiccia = Ingredient::factory()->tracked(0)->create(['name' => 'Salsiccia']);
    $food->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Esaurito')
        ->call('addFood', $food->id);

    expect($component->get('cart'))->toBeEmpty();
});

it('blocks adding more portions than the tracked stock allows', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $salsiccia = Ingredient::factory()->tracked(1)->create();
    $food->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)   // ok: consumes the only unit
        ->call('addFood', $food->id);  // blocked: stock exhausted

    expect($component->get('cart'))->toHaveCount(1)
        ->and(collect($component->get('cart'))->first()['quantity'])->toBe(1);
});

it('blocks incrementing a cart line beyond the tracked stock', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $ingredient = Ingredient::factory()->tracked(1)->create();
    $food->ingredients()->attach($ingredient->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    $key = array_key_first($component->get('cart'));
    $component->call('incrementLine', $key);

    expect(collect($component->get('cart'))->first()['quantity'])->toBe(1);
});

it('marks a food sold out when a shared ingredient is used up by another food in the cart', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $panino = Food::factory()->create(['category_id' => $category->id, 'name' => 'Panino']);
    $piadina = Food::factory()->create(['category_id' => $category->id, 'name' => 'Piadina']);
    $salsiccia = Ingredient::factory()->tracked(1)->create(['name' => 'Salsiccia']);
    $panino->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);
    $piadina->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $panino->id)    // consumes the only shared unit
        ->call('addFood', $piadina->id)   // blocked: shared ingredient gone
        ->assertSee('Esaurito');

    expect($component->get('cart'))->toHaveCount(1);
});

it('never blocks a food whose ingredients are untracked', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $ingredient = Ingredient::factory()->create(); // stock null: untracked
    $food->ingredients()->attach($ingredient->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('addFood', $food->id)
        ->assertDontSee('Esaurito');

    expect(collect($component->get('cart'))->first()['quantity'])->toBe(2);
});

it('opens the sold-out modal at checkout when an ingredient ran out after adding', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $salsiccia = Ingredient::factory()->tracked(1)->create(['name' => 'Salsiccia']);
    $food->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    // Another register buys the last unit before this one checks out.
    $salsiccia->update(['stock' => 0]);

    $component->call('choosePickup');
    $component->call('startCash')->call('setExactCash')->call('confirmCash')
        ->assertSet('showSoldOut', true)        // sold-out modal opens
        ->assertSet('showCashModal', false)     // payment modal closes
        ->assertSee('Salsiccia');               // the missing ingredient is listed

    expect(Order::count())->toBe(0)             // nothing placed
        ->and($component->get('soldOutItems'))->toHaveCount(1)
        ->and($component->get('soldOutItems')[0]['name'])->toBe('Salsiccia')
        ->and($component->get('soldOutItems')[0]['missing'])->toBe(1);

    // The cart is kept intact so the cashier can amend the order.
    $component->call('closeSoldOut')->assertSet('showSoldOut', false);
    expect($component->get('cart'))->toHaveCount(1);
});

it('lists every missing ingredient in the sold-out modal', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $pane = Ingredient::factory()->tracked(5)->create(['name' => 'Pane']);
    $salsiccia = Ingredient::factory()->tracked(5)->create(['name' => 'Salsiccia']);
    $food->ingredients()->attach($pane->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);
    $food->ingredients()->attach($salsiccia->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    // Both ingredients run out before checkout.
    $pane->update(['stock' => 0]);
    $salsiccia->update(['stock' => 0]);

    $component->call('choosePickup');
    $component->call('startCash')->call('setExactCash')->call('confirmCash')
        ->assertSet('showSoldOut', true)
        ->assertSee('Pane')
        ->assertSee('Salsiccia');

    expect($component->get('soldOutItems'))->toHaveCount(2);
});

/**
 * A register, an event day and a food using one tracked ingredient (dose 1).
 *
 * @return array{0: CashRegister, 1: Food, 2: Ingredient}
 */
function registerFoodTracked(int $stock = 3): array
{
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id]);
    $ingredient = Ingredient::factory()->tracked($stock)->create(['name' => 'Salsiccia']);
    $food->ingredients()->attach($ingredient->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    return [$register, $food, $ingredient];
}

it('reserves ingredient stock when the card payment starts', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard')
        ->assertSet('showCardModal', true);

    expect($ingredient->fresh()->stock)->toBe(2)  // one unit held for the pending payment
        ->and(StockReservation::count())->toBe(1);
});

it('releases the reservation when the card payment is cancelled', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    expect($ingredient->fresh()->stock)->toBe(2);

    $component->call('closeCard')
        ->assertSet('showCardModal', false)
        ->assertSet('reservationId', null);

    expect($ingredient->fresh()->stock)->toBe(3)   // stock restored
        ->and(StockReservation::count())->toBe(0);
});

it('does not decrement stock again when confirming a reserved payment', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard')
        ->call('confirmCard')
        ->assertHasNoErrors();

    expect(Order::count())->toBe(1)
        ->and($ingredient->fresh()->stock)->toBe(2)    // held unit consumed once, not twice
        ->and(StockReservation::count())->toBe(0);     // hold consumed by the order
});

it('shows sold out and never opens the card modal when the stock ran out at payment start', function () {
    [$register, $food, $ingredient] = registerFoodTracked(1);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    // Another register takes the last unit before this one starts paying.
    $ingredient->update(['stock' => 0]);

    $component->call('choosePickup');
    $component->call('startCard')
        ->assertSet('showCardModal', false)   // customer is never charged
        ->assertSet('showSoldOut', true)
        ->assertSee('Salsiccia');

    expect(StockReservation::count())->toBe(0)
        ->and($ingredient->fresh()->stock)->toBe(0);
});

it('marks a food sold out for other registers while its stock is held for a payment', function () {
    [$registerA, $food, $ingredient] = registerFoodTracked(1);

    // Register A starts paying, holding the only unit.
    Livewire::test('pages::pos')
        ->call('selectRegister', $registerA->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    // Register B now sees the food as sold out.
    Livewire::test('pages::pos')
        ->call('selectRegister', CashRegister::factory()->create()->id)
        ->assertSee('Esaurito');
});

it('keeps the single reservation when switching from card to cash', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    $reservationId = $component->get('reservationId');

    $component->call('cardToCash')
        ->assertSet('showCardModal', false)
        ->assertSet('showCashModal', true)
        ->assertSet('reservationId', $reservationId);

    expect($ingredient->fresh()->stock)->toBe(2)   // not decremented a second time
        ->and(StockReservation::count())->toBe(1);
});

it('keeps the reservation alive while the payment screen stays open', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    $reservationId = $component->get('reservationId');

    // The hold is about to expire; the browser heartbeat renews it.
    StockReservation::whereKey($reservationId)->update(['expires_at' => now()->subMinute()]);
    $component->call('keepReservationAlive')
        ->assertSet('reservationId', $reservationId);

    expect(StockReservation::releaseExpired())->toBe(0)   // no longer expired
        ->and($ingredient->fresh()->stock)->toBe(2);       // still held
});

it('re-acquires the hold on heartbeat when it was released mid-payment', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    $oldId = $component->get('reservationId');

    // The hold expires and the cron releases it (stock back to 3).
    StockReservation::whereKey($oldId)->update(['expires_at' => now()->subMinute()]);
    StockReservation::releaseExpired();
    expect($ingredient->fresh()->stock)->toBe(3)
        ->and(StockReservation::count())->toBe(0);

    // The heartbeat re-acquires it seamlessly, payment continues.
    $component->call('keepReservationAlive')
        ->assertSet('showCardModal', true)
        ->assertSet('showSoldOut', false);

    expect($component->get('reservationId'))->not->toBeNull()
        ->and($component->get('reservationId'))->not->toBe($oldId)
        ->and($ingredient->fresh()->stock)->toBe(2)
        ->and(StockReservation::count())->toBe(1);
});

it('stops the payment and shows sold out when the hold cannot be re-acquired', function () {
    [$register, $food, $ingredient] = registerFoodTracked(1);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    $oldId = $component->get('reservationId');

    // Hold released by the cron, then another register takes the last unit.
    StockReservation::whereKey($oldId)->update(['expires_at' => now()->subMinute()]);
    StockReservation::releaseExpired();  // stock back to 1
    $ingredient->update(['stock' => 0]); // taken elsewhere

    $component->call('keepReservationAlive')
        ->assertSet('showCardModal', false)
        ->assertSet('showSoldOut', true)
        ->assertSee('Salsiccia');

    expect($component->get('reservationId'))->toBeNull()
        ->and(StockReservation::count())->toBe(0);
});

it('does nothing on heartbeat when no payment modal is open', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('keepReservationAlive');

    expect($component->get('reservationId'))->toBeNull()
        ->and(StockReservation::count())->toBe(0)
        ->and($ingredient->fresh()->stock)->toBe(3);
});

it('freezes the cart while a payment is in progress', function () {
    [$register, $food, $ingredient] = registerFoodTracked(5);
    $second = Food::factory()->create(['category_id' => $food->category_id]);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard'); // payment in progress

    $key = array_key_first($component->get('cart'));

    // Every cart mutation is ignored while a payment is under way.
    $component->call('addFood', $second->id)
        ->call('incrementLine', $key)
        ->call('decrementLine', $key)
        ->call('editLine', $key)
        ->call('openClearCart');

    expect($component->get('cart'))->toHaveCount(1)
        ->and(collect($component->get('cart'))->first()['quantity'])->toBe(1)
        ->and($component->get('editingKey'))->toBeNull()
        ->and($component->get('showClearCart'))->toBeFalse();
});

it('cancels the payment and warns the cashier when the hold exceeds the max hold', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('choosePickup')
        ->call('startCard');

    $reservationId = $component->get('reservationId');
    expect($ingredient->fresh()->stock)->toBe(2);

    // The payment screen has been open past the absolute max hold.
    $maxHold = (int) config('inventory.max_hold', 900);
    StockReservation::whereKey($reservationId)->update(['created_at' => now()->subSeconds($maxHold + 60)]);

    $component->call('keepReservationAlive')
        ->assertSet('showCardModal', false)
        ->assertSet('showReservationExpired', true)
        ->assertSet('reservationId', null);

    expect($ingredient->fresh()->stock)->toBe(3)         // held stock released
        ->and(StockReservation::count())->toBe(0)
        ->and($component->get('cart'))->toHaveCount(1);   // cart kept

    // Dismissing the notice returns to the cart, editable again.
    $component->call('closeReservationExpired')->assertSet('showReservationExpired', false);
    $component->call('incrementLine', array_key_first($component->get('cart')));
    expect(collect($component->get('cart'))->first()['quantity'])->toBe(2);
});

it('gives the held stock back when the order fails after the hold was claimed', function () {
    [$register, $food, $ingredient] = registerFoodTracked(3);

    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id);

    // A note longer than the domain accepts makes place() throw at commit,
    // after the hold has already been claimed for the order.
    $key = array_key_first($component->get('cart'));
    $component->call('editLine', $key)
        ->set('customizeNote', str_repeat('a', 300))
        ->call('confirmCustomize')
        ->call('choosePickup')
        ->call('startCard');

    expect($ingredient->fresh()->stock)->toBe(2); // held for the payment

    $component->call('confirmCard')->assertHasErrors('checkout');

    expect(Order::count())->toBe(0)
        ->and($ingredient->fresh()->stock)->toBe(3)      // held units given back
        ->and(StockReservation::count())->toBe(0)
        ->and($component->get('reservationId'))->toBeNull()
        ->and($component->get('cart'))->toHaveCount(1);  // cart kept for a retry
});

it('lets the tablet be assigned to its register before the day is open', function () {
    // No open day: this is setup time, and the station is setup, not selling.
    $register = CashRegister::factory()->create(['name' => 'Cassa Griglia']);

    $component = Livewire::test('pages::pos')
        ->assertSee('Seleziona la cassa')
        ->assertSee('Nessuna giornata aperta')   // said up front, so the pick is not a dead end
        ->call('selectRegister', $register->id)
        ->assertSee('Cassa Griglia');            // now waiting for the day, station in hand

    expect(session('pos_cash_register_id'))->toBe($register->id);

    // And it can still be changed from there, which is the only screen reachable.
    $component->call('changeRegister')
        ->assertSee('Seleziona la cassa');

    expect(session('pos_cash_register_id'))->toBeNull();
});

it('asks for the register before anything else once a day is open', function () {
    openDay();
    $register = CashRegister::factory()->create(['name' => 'Cassa Bar']);

    Livewire::test('pages::pos')
        ->assertSee('Seleziona la cassa')
        ->assertDontSee('Nessuna giornata aperta')
        ->call('selectRegister', $register->id)
        ->assertDontSee('Seleziona la cassa');
});

it('prints the food names on the keys at one fixed size', function () {
    openDay();
    $register = CashRegister::factory()->create();
    Food::factory()->create(['category_id' => Category::factory()->create()->id]);

    // Fixed on purpose: the keys are read at a glance, and a name that changes
    // size from one board or one screen to the next is read slower.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('text-xl font-semibold', escape: false);
});

it('counts an emptied covers field as no covers without refilling it', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $settings = app(EventSettings::class);
    $settings->coverCharge = 200;
    $settings->save();

    // Deleting what is typed sends null. The box stays empty while the cashier
    // types the new number, and counts as no covers in the meantime.
    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->set('covers', 4)
        ->set('covers', null)
        ->assertSet('covers', null);

    expect($component->instance()->coverTotal)->toBe(0);

    // Leaving the field settles it on the 0 it already counted as.
    $component->call('normalizeCovers')->assertSet('covers', 0);
});

it('counts up and down from an emptied covers field', function () {
    openDay();
    $register = CashRegister::factory()->create();

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->set('covers', null)
        ->call('incCovers')
        ->assertSet('covers', 1)
        ->set('covers', null)
        ->call('decCovers')
        ->assertSet('covers', 0)
        ->set('covers', 999)
        ->call('incCovers')
        ->assertSet('covers', 999);
});

it('gives every menu key and cart line an identity of its own in the dom', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id]);

    // Without these, Livewire matches by position: remove a line from the
    // middle of the cart and the note or the quantity of the next one is left
    // sitting on the wrong dish.
    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('wire:key="food-'.$food->id.'"', escape: false)
        ->call('addFood', $food->id);

    $component->assertSee('wire:key="line-'.array_key_first($component->get('cart')).'"', escape: false);
});

it('abbreviates a key with the short name and keeps the full one everywhere else', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create([
        'category_id' => Category::factory()->create()->id,
        'name' => 'Salsiccia e patatine con pane',
        'short_name' => 'Sals. + pat.',
    ]);

    // The abbreviation buys room on the key; the cart, and the receipt it is
    // snapshotted into, say what the customer will read on paper.
    $component = Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Sals. + pat.')
        ->assertDontSee('Salsiccia e patatine con pane')
        ->call('addFood', $food->id)
        ->assertSee('Salsiccia e patatine con pane');

    expect(head($component->get('cart'))['name'])->toBe('Salsiccia e patatine con pane');
});

it('falls back to the full name on a key with no short name', function () {
    openDay();
    $register = CashRegister::factory()->create();
    Food::factory()->create([
        'category_id' => Category::factory()->create()->id,
        'name' => 'Brasato',
        'short_name' => '   ',
    ]);

    // A field left blank (spaces included) is no abbreviation at all.
    expect(Food::first()->short_name)->toBeNull();

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('Brasato');
});

it('shows the portions left on a key made of tracked stock', function () {
    [$register, $food] = registerFoodTracked(7);

    // Six after one goes in the cart: the key counts what can still be sold,
    // not what the warehouse started with.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('7 porzioni')
        ->call('addFood', $food->id)
        ->assertSee('6 porzioni');
});

it('counts the portions by the scarcest tracked ingredient and its dose', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id]);
    $bread = Ingredient::factory()->tracked(10)->create(['name' => 'Pane']);
    $sausage = Ingredient::factory()->tracked(9)->create(['name' => 'Salsiccia']);
    $food->ingredients()->attach($bread->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);
    $food->ingredients()->attach($sausage->id, ['quantity' => 2, 'min_quantity' => 2, 'max_quantity' => 2]);

    // Nine sausages at two apiece make four portions, and the ten loaves do not
    // make it five: the shortest ingredient decides.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee('4 porzioni');
});

it('leaves a key without a stock figure when nothing it is made of is tracked', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create(['category_id' => Category::factory()->create()->id]);
    $ingredient = Ingredient::factory()->create(['name' => 'Sale', 'stock' => null]);
    $food->ingredients()->attach($ingredient->id, ['quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1]);

    // Untracked is unlimited, and an unlimited food has no number to print: a
    // blank there says that, a zero would say the opposite.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->assertSee($food->name)
        ->assertDontSee('porzioni');
});

it('writes a cart line name across the whole column', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $food = Food::factory()->create([
        'category_id' => Category::factory()->create()->id,
        'name' => 'Brasato con polenta e funghi',
    ]);

    // The name sits on its own row, unclipped: sharing the row with the edit
    // and quantity buttons left it a hundred pixels on a 10" tablet and cut it
    // mid-word.
    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->assertSee('<div class="text-lg font-semibold leading-tight">Brasato con polenta e funghi</div>', escape: false);
});

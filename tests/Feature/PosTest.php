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
        ->set('tableNumber', 3)
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
        ->set('tableNumber', 3)
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
        ->set('tableNumber', 1)
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
        ->set('tableNumber', 1)
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
        ->set('tableNumber', 1)
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
        ->set('tableNumber', 5)
        ->call('openClearCart')
        ->assertSet('showClearCart', true)
        ->call('clearCart');

    expect($component->get('cart'))->toBeEmpty()
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
        ->set('tableNumber', 7)
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

it('places a pickup order when no table number is set', function () {
    openDay();
    $register = CashRegister::factory()->create();
    $category = Category::factory()->create();
    $food = Food::factory()->create(['category_id' => $category->id, 'price' => 500]);

    Livewire::test('pages::pos')
        ->call('selectRegister', $register->id)
        ->call('addFood', $food->id)
        ->call('startCard')
        ->call('confirmCard')
        ->assertHasNoErrors();

    expect(Order::first()->service_type)->toBe(ServiceType::Pickup);
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

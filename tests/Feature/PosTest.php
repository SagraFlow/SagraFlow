<?php

use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Order;
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
        ->assertSee('€ 8,00') // 5,00 goods + 2 × 1,50 coperto
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

    // base 1000 + 200 = 1200; −10% = 120; total 1080
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
        ->and($order->total)->toBe(600);    // 500 + 1 × 100
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

    // Frozen flag = true → base 1000 + 200 = 1200; −10% = 120; total 1080
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
        ->set('discountValue', 5)
        ->call('openDiscount')
        ->set('discountType', 'percentage')
        ->set('discountValue', 50)
        ->call('cancelDiscount');

    expect($component->get('discountType'))->toBe('fixed')
        ->and($component->get('discountValue'))->toBe(5.0);

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
        ->call('addCash', 5) // only 5€ for a 10€ order
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
        ->call('addCash', 20);

    expect($component->get('cashReceived'))->toBe(20.0);
    // 20€ received - 12€ total = 8€ change
    $component->call('confirmCash')->assertHasNoErrors();

    expect(Order::first()->total)->toBe(1200);
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

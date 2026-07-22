<?php

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Exceptions\OrderException;
use App\Models\CashRegister;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A food with a Salamina ingredient (base dose 1, up to 2, +2€ surcharge).
 *
 * @return array{0: Food, 1: Ingredient}
 */
function foodWithSalamina(int $price = 400, int $base = 1, int $min = 1, int $max = 2, int $surcharge = 200): array
{
    $food = Food::factory()->create(['price' => $price]);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => $surcharge]);
    $food->ingredients()->attach($salamina->id, ['quantity' => $base, 'min_quantity' => $min, 'max_quantity' => $max]);

    return [$food->fresh(), $salamina];
}

/**
 * A frozen cart line snapshot for a food, as the POS hands it to Order::place().
 *
 * @param  array<int, array<string, mixed>>  $ingredients
 * @return array<string, mixed>
 */
function frozenLine(Food $food, int $quantity = 1, array $ingredients = [], ?string $note = null): array
{
    return [
        'food_id' => $food->id,
        'food_name' => $food->name,
        'unit_price' => $food->price,
        'quantity' => $quantity,
        'note' => $note,
        'ingredients' => $ingredients,
    ];
}

/**
 * A frozen ingredient snapshot for a cart line.
 *
 * @return array<string, mixed>
 */
function frozenIngredient(Ingredient $ingredient, int $quantity, int $baseQuantity): array
{
    return [
        'ingredient_id' => $ingredient->id,
        'ingredient_name' => $ingredient->name,
        'quantity' => $quantity,
        'base_quantity' => $baseQuantity,
        'surcharge' => $ingredient->surcharge,
    ];
}

it('places a paid table order with a progressive number and frozen snapshots', function () {
    $day = EventDay::factory()->create();
    $register = CashRegister::factory()->create();
    $operator = User::factory()->create();
    [$food, $salamina] = foodWithSalamina();

    $order = Order::place($day, $register, $operator, 5, 'Mario', PaymentMethod::Cash, [
        frozenLine($food, 1, [frozenIngredient($salamina, 1, 1)]),
    ]);

    expect($order->number)->toBe(1)
        ->and($order->service_type)->toBe(ServiceType::TableService)
        ->and($order->table_number)->toBe(5)
        ->and($order->customer_name)->toBe('Mario')
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->subtotal)->toBe(400)
        ->and($order->total)->toBe(400)
        ->and($order->lines->first()->food_name)->toBe($food->name)
        ->and($order->lines->first()->unit_price)->toBe(400);
});

it('persists the frozen line snapshot instead of the current food price', function () {
    $day = EventDay::factory()->create();
    $food = Food::factory()->create(['name' => 'Panino', 'price' => 500]);

    // The cart froze 500; the food is repriced to 700 before checkout.
    $line = frozenLine($food, 2);
    $food->update(['price' => 700]);

    $order = Order::place($day, null, null, null, null, PaymentMethod::Cash, [$line]);

    expect($order->lines->first()->unit_price)->toBe(500)
        ->and($order->total)->toBe(1000);
});

it('records a line even when the referenced food no longer exists', function () {
    $day = EventDay::factory()->create();

    $order = Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        [
            'food_id' => null,
            'food_name' => 'Pietanza rimossa',
            'unit_price' => 500,
            'quantity' => 2,
            'note' => null,
            'ingredients' => [],
        ],
    ]);

    expect($order->lines->first()->food_id)->toBeNull()
        ->and($order->lines->first()->food_name)->toBe('Pietanza rimossa')
        ->and($order->total)->toBe(1000);
});

it('adds the cover charge to the total and freezes the per-cover amount', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 400);

    $order = Order::place($day, null, null, 5, 'Mario', PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], covers: 4, coverCharge: 150);

    expect($order->cover_charge)->toBe(150)
        ->and($order->coverTotal())->toBe(600)
        ->and($order->subtotal)->toBe(400)
        ->and($order->total)->toBe(1000); // 400 goods + 4 × 150 coperto
});

it('applies the discount before adding the cover charge', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 1000);

    $order = Order::place($day, null, null, 2, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Percentage, 10, covers: 3, coverCharge: 200);

    // 1000 − 10% = 900 discounted goods, + 3 × 200 coperto = 1500
    expect($order->discount_amount)->toBe(100)
        ->and($order->total)->toBe(1500);
});

it('discounts the cover charge when the setting is enabled', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 1000);

    $order = Order::place($day, null, null, 2, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Percentage, 10, covers: 3, coverCharge: 200, discountAppliesToCover: true);

    // base = 1000 goods + 3 × 200 coperto = 1600; 10% discount = 160; total = 1440
    expect($order->discount_amount)->toBe(160)
        ->and($order->total)->toBe(1440);
});

it('stores the discount-applies-to-cover choice on the order', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 1000);

    $order = Order::place($day, null, null, 1, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Percentage, 10, covers: 2, coverCharge: 200, discountAppliesToCover: true);

    expect($order->fresh()->discount_applies_to_cover)->toBeTrue()
        ->and($order->total)->toBe(1260); // base 1000 + 400 coperto = 1400; −10% = 140; total 1260
});

it('derives the service type from the table number', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina();

    $table = Order::place($day, null, null, 7, null, PaymentMethod::Cash, [frozenLine($food, 1)]);
    $pickup = Order::place($day, null, null, null, null, PaymentMethod::Cash, [frozenLine($food, 1)]);

    expect($table->service_type)->toBe(ServiceType::TableService)
        ->and($pickup->service_type)->toBe(ServiceType::Pickup)
        ->and($pickup->table_number)->toBeNull();
});

it('charges the surcharge only on units above the base dose', function () {
    $day = EventDay::factory()->create();
    [$food, $salamina] = foodWithSalamina(price: 400, base: 1, max: 2, surcharge: 200);

    $order = Order::place($day, null, null, null, null, PaymentMethod::Card, [
        frozenLine($food, 2, [frozenIngredient($salamina, 2, 1)]),
    ]);

    // (400 base + 200 for the 1 extra salamina) * 2 portions
    expect($order->total)->toBe(1200);
});

it('numbers orders progressively within a day and independently across days', function () {
    $day1 = EventDay::factory()->create(['date' => '2026-07-10']);
    $day2 = EventDay::factory()->create(['date' => '2026-07-11']);
    [$food] = foodWithSalamina();

    $a = Order::place($day1, null, null, 1, null, PaymentMethod::Cash, [frozenLine($food, 1)]);
    $b = Order::place($day1, null, null, 2, null, PaymentMethod::Cash, [frozenLine($food, 1)]);
    $c = Order::place($day2, null, null, null, null, PaymentMethod::Cash, [frozenLine($food, 1)]);

    expect([$a->number, $b->number, $c->number])->toBe([1, 2, 1]);
});

it('applies a fixed discount to the total', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 400);

    $order = Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Fixed, 100);

    expect($order->subtotal)->toBe(400)
        ->and($order->discount_amount)->toBe(100)
        ->and($order->total)->toBe(300);
});

it('applies a percentage discount to the subtotal', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 1000);

    $order = Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Percentage, 10);

    expect($order->subtotal)->toBe(1000)
        ->and($order->discount_amount)->toBe(100)
        ->and($order->total)->toBe(900);
});

it('never discounts more than the subtotal', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina(price: 400);

    $order = Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Fixed, 5000);

    expect($order->discount_amount)->toBe(400)
        ->and($order->total)->toBe(0);
});

it('rejects a percentage discount outside 0–100', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina();

    Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Percentage, 150);
})->throws(OrderException::class);

it('rejects a negative fixed discount', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina();

    Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        frozenLine($food, 1),
    ], DiscountType::Fixed, -100);
})->throws(OrderException::class);

it('never returns a negative discount amount', function () {
    expect(Order::calculateDiscount(1000, DiscountType::Fixed, -500))->toBe(0);
});

it('rejects a customer name longer than 255 characters', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina();

    Order::place($day, null, null, null, str_repeat('a', 256), PaymentMethod::Cash, [
        frozenLine($food, 1),
    ]);
})->throws(OrderException::class);

it('rejects a line note longer than 255 characters', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina();

    Order::place($day, null, null, null, null, PaymentMethod::Cash, [
        frozenLine($food, 1, [], str_repeat('a', 256)),
    ]);
})->throws(OrderException::class);

it('stores the covers count and per-line notes', function () {
    $day = EventDay::factory()->create();
    [$food] = foodWithSalamina();

    $order = Order::place($day, null, null, 5, 'Mario', PaymentMethod::Cash, [
        frozenLine($food, 1, [], 'ben cotto'),
    ], null, null, 4);

    expect($order->covers)->toBe(4)
        ->and($order->lines->first()->note)->toBe('ben cotto');
});

it('rejects an empty order', function () {
    $day = EventDay::factory()->create();

    Order::place($day, null, null, null, null, PaymentMethod::Cash, []);
})->throws(OrderException::class);

<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\CashRegister;
use App\Models\EventDay;
use App\Models\Food;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Place a realistic paid order (one customized line) for listing/detail tests.
 */
function placedOrder(): Order
{
    $day = EventDay::factory()->create();
    $register = CashRegister::factory()->create(['name' => 'Cassa 1']);
    $food = Food::factory()->create(['name' => 'Panino']);
    $salamina = Ingredient::factory()->create(['name' => 'Salamina', 'surcharge' => 200]);

    return Order::place($day, $register, User::factory()->create(), 5, 'Mario', PaymentMethod::Cash, [
        [
            'food_id' => $food->id,
            'food_name' => $food->name,
            'unit_price' => 500,
            'quantity' => 2,
            'note' => 'ben cotto',
            'ingredients' => [
                [
                    'ingredient_id' => $salamina->id,
                    'ingredient_name' => $salamina->name,
                    'quantity' => 2,
                    'base_quantity' => 1,
                    'surcharge' => 200,
                ],
            ],
        ],
    ], covers: 4, coverCharge: 150);
}

it('lists the orders', function () {
    $order = placedOrder();

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$order])
        ->assertSee('Mario')      // customer column
        ->assertSee('Cassa 1');   // register column
});

it('exposes a read-only view action that opens the detail modal', function () {
    $order = placedOrder();

    Livewire::test(ListOrders::class)
        ->assertActionExists(TestAction::make('view')->table($order))
        ->mountAction(TestAction::make('view')->table($order))
        ->assertActionMounted(TestAction::make('view')->table($order))
        ->assertHasNoErrors();
});

it('renders the detail view with the order data', function () {
    $order = placedOrder()->loadMissing([
        'lines.ingredients', 'lines.food.category', 'eventDay', 'cashRegister', 'operator',
    ]);

    $html = view('filament.orders.detail', ['order' => $order])->render();

    expect($html)
        ->toContain('Panino')
        ->toContain('+1 Salamina')
        ->toContain('ben cotto')
        ->toContain('Mario')
        ->toContain('€ 20.00'); // (500 + 200 surcharge) x 2 + 4 x 150 coperto
});

it('summarises the line customizations for the detail', function () {
    $line = placedOrder()->lines->first();

    expect($line->deviationSummary())->toBe('+1 Salamina')
        ->and($line->note)->toBe('ben cotto');
});

it('does not allow creating, editing or deleting orders', function () {
    expect(OrderResource::canCreate())->toBeFalse()
        ->and(OrderResource::canEdit(placedOrder()))->toBeFalse()
        ->and(OrderResource::canDelete(Order::first()))->toBeFalse();
});

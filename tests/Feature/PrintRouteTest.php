<?php

use App\Enums\PrintDestination;
use App\Enums\ServiceType;
use App\Models\Category;
use App\Models\Printer;
use App\Models\PrintRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts service type, destination and grouped', function () {
    $route = PrintRoute::factory()->create([
        'service_type' => ServiceType::Pickup,
        'destination' => PrintDestination::CashRegister,
        'printer_id' => null,
        'grouped' => false,
    ])->fresh();

    expect($route->service_type)->toBe(ServiceType::Pickup)
        ->and($route->destination)->toBe(PrintDestination::CashRegister)
        ->and($route->grouped)->toBeFalse();
});

it('belongs to a category and a printer', function () {
    $route = PrintRoute::factory()->create();

    expect($route->category)->toBeInstanceOf(Category::class)
        ->and($route->printer)->toBeInstanceOf(Printer::class);
});

it('has a cash register destination without a fixed printer', function () {
    $route = PrintRoute::factory()->toCashRegister()->create();

    expect($route->destination)->toBe(PrintDestination::CashRegister)
        ->and($route->printer_id)->toBeNull();
});

it('clears a stray printer on a cash register destination', function () {
    $printer = Printer::factory()->create();

    $route = PrintRoute::factory()->create([
        'destination' => PrintDestination::CashRegister,
        'printer_id' => $printer->id,
    ]);

    expect($route->fresh()->printer_id)->toBeNull();
});

it('keeps a standalone covers route without a category', function () {
    $route = PrintRoute::factory()->forCovers()->create();

    expect($route->fresh()->for_covers)->toBeTrue()
        ->and($route->fresh()->category_id)->toBeNull();
});

it('never flags a category-bound route as a covers route', function () {
    // A stray for_covers flag must not detach the route from its category.
    $route = PrintRoute::factory()->create(['for_covers' => true]);

    expect($route->fresh()->category_id)->not->toBeNull()
        ->and($route->fresh()->for_covers)->toBeFalse();
});

it('is deleted together with its category', function () {
    $route = PrintRoute::factory()->create();

    $route->category->delete();

    expect(PrintRoute::whereKey($route->id)->exists())->toBeFalse();
});

it('allows several routes for the same category and service (double print)', function () {
    $category = Category::factory()->create();
    $category->printRoutes()->delete(); // start from a clean slate (categories seed defaults)

    PrintRoute::factory()->for($category)->create(['service_type' => ServiceType::Pickup]);
    PrintRoute::factory()->for($category)->toCashRegister()->create(['service_type' => ServiceType::Pickup]);

    expect($category->printRoutes()->where('service_type', ServiceType::Pickup)->count())->toBe(2);
});

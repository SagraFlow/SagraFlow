<?php

use App\Models\Ingredient;
use App\Models\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('holds tracked ingredient stock and records the held units', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();

    $reservation = StockReservation::reserve([$ingredient->id => 3], 300);

    expect($reservation)->not->toBeNull()
        ->and($ingredient->fresh()->stock)->toBe(7)
        ->and($reservation->held)->toBe([$ingredient->id => 3]);
});

it('returns null and holds nothing when a tracked ingredient is short', function () {
    $ingredient = Ingredient::factory()->tracked(2)->create();

    $reservation = StockReservation::reserve([$ingredient->id => 3], 300);

    expect($reservation)->toBeNull()
        ->and($ingredient->fresh()->stock)->toBe(2)
        ->and(StockReservation::count())->toBe(0);
});

it('never holds an untracked ingredient', function () {
    $ingredient = Ingredient::factory()->create(); // stock null

    $reservation = StockReservation::reserve([$ingredient->id => 100], 300);

    expect($reservation)->not->toBeNull()
        ->and($reservation->held)->toBe([])
        ->and($ingredient->fresh()->stock)->toBeNull();
});

it('rolls back earlier holds when a later ingredient is short', function () {
    $a = Ingredient::factory()->tracked(10)->create();
    $b = Ingredient::factory()->tracked(1)->create();

    $reservation = StockReservation::reserve([$a->id => 5, $b->id => 3], 300);

    expect($reservation)->toBeNull()
        ->and($a->fresh()->stock)->toBe(10)  // first hold rolled back
        ->and($b->fresh()->stock)->toBe(1);
});

it('gives the held units back and deletes the reservation on release', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $reservation = StockReservation::reserve([$ingredient->id => 4], 300);

    $reservation->release();

    expect($ingredient->fresh()->stock)->toBe(10)
        ->and(StockReservation::count())->toBe(0);
});

it('releases only expired reservations', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $fresh = StockReservation::reserve([$ingredient->id => 2], 300);
    $stale = StockReservation::reserve([$ingredient->id => 3], 300);
    $stale->update(['expires_at' => now()->subMinute()]); // already expired
    // stock is now 10 - 2 - 3 = 5

    $released = StockReservation::releaseExpired();

    expect($released)->toBe(1)
        ->and($ingredient->fresh()->stock)->toBe(8)               // 5 + 3 given back
        ->and(StockReservation::find($fresh->id))->not->toBeNull()
        ->and(StockReservation::find($stale->id))->toBeNull();
});

it('releases expired reservations through the scheduled command', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $stale = StockReservation::reserve([$ingredient->id => 4], 300);
    $stale->update(['expires_at' => now()->subMinute()]);

    $this->artisan('stock:release-reservations')->assertSuccessful();

    expect($ingredient->fresh()->stock)->toBe(10)
        ->and(StockReservation::count())->toBe(0);
});

it('gives nothing back when releasing a hold the reaper already freed', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $reservation = StockReservation::reserve([$ingredient->id => 4], 300); // stock 6

    // The hold expires and the cron frees it (stock back to 10); the checkout
    // then cancels its payment with a model it had already loaded.
    StockReservation::whereKey($reservation->id)->update(['expires_at' => now()->subMinute()]);
    StockReservation::releaseExpired();

    $reservation->release();

    expect($ingredient->fresh()->stock)->toBe(10) // given back once, not twice
        ->and(StockReservation::count())->toBe(0);
});

it('claims a hold for an order without giving its units back', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $reservation = StockReservation::reserve([$ingredient->id => 4], 300);

    expect($reservation->claim())->toBeTrue()
        ->and($ingredient->fresh()->stock)->toBe(6)  // stays decremented for the order
        ->and(StockReservation::count())->toBe(0);
});

it('cannot claim a hold that was already released', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $reservation = StockReservation::reserve([$ingredient->id => 4], 300);
    $reservation->release();

    expect($reservation->claim())->toBeFalse()
        ->and($ingredient->fresh()->stock)->toBe(10);
});

it('pushes the expiry forward on renew so the cron no longer reaps it', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();
    $reservation = StockReservation::reserve([$ingredient->id => 2], 300);
    $reservation->update(['expires_at' => now()->subMinute()]); // about to be reaped

    $reservation->renew(300);

    expect($reservation->fresh()->expires_at->isFuture())->toBeTrue()
        ->and(StockReservation::releaseExpired())->toBe(0)
        ->and($ingredient->fresh()->stock)->toBe(8); // still held
});

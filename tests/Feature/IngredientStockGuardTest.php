<?php

use App\Filament\Resources\Ingredients\Pages\ManageIngredients;
use App\Models\Ingredient;
use App\Models\StockReservation;
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

it('blocks editing an ingredient stock while a reservation holds it', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create(['name' => 'Salsiccia']);
    StockReservation::reserve([$ingredient->id => 2], 300); // holds 2, stock now 8

    Livewire::test(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($ingredient->fresh()), data: ['stock' => 50])
        ->assertNotified();

    expect($ingredient->fresh()->stock)->toBe(8); // unchanged
});

it('allows editing an ingredient stock when no reservation holds it', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create();

    Livewire::test(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($ingredient), data: ['stock' => 50])
        ->assertHasNoActionErrors();

    expect($ingredient->fresh()->stock)->toBe(50);
});

it('allows editing other fields while a reservation holds the ingredient', function () {
    $ingredient = Ingredient::factory()->tracked(10)->create(['name' => 'Pane']);
    StockReservation::reserve([$ingredient->id => 2], 300);

    Livewire::test(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($ingredient->fresh()), data: ['name' => 'Pane comune']);

    expect($ingredient->fresh()->name)->toBe('Pane comune')
        ->and($ingredient->fresh()->stock)->toBe(8); // stock untouched
});

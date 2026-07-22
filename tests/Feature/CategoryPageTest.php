<?php

use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Enums\ServiceType;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Printer;
use App\Models\PrintRoute;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('mounts the categories list page', function () {
    Livewire::test(ListCategories::class)->assertOk();
});

it('creates a category with print routes from the create page', function () {
    $printer = Printer::factory()->create();

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Griglia',
            'active' => true,
            'printRoutes_table_service' => [
                [
                    'document' => PrintJobType::DepartmentTicket->value,
                    'destination' => PrintDestination::DepartmentPrinter->value,
                    'printer_id' => $printer->id,
                    'grouped' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $category = Category::firstWhere('name', 'Griglia');

    expect($category)->not->toBeNull()
        ->and($category->printRoutes()->where('service_type', ServiceType::TableService)->count())->toBe(1);
});

it('mounts the category edit page with the service tabs', function () {
    $category = Category::factory()->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->assertOk();
});

it('saves one service without deleting the others', function () {
    $category = Category::factory()->create();
    $printer = Printer::factory()->create();
    PrintRoute::factory()->for($category)->toCashRegister()->create(['service_type' => ServiceType::Pickup]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm([
            'printRoutes_table_service' => [
                [
                    'document' => PrintJobType::DepartmentTicket->value,
                    'destination' => PrintDestination::DepartmentPrinter->value,
                    'printer_id' => $printer->id,
                    'grouped' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $category->refresh();

    expect($category->printRoutes()->where('service_type', ServiceType::TableService)->count())->toBe(1)
        ->and($category->printRoutes()->where('service_type', ServiceType::Pickup)->count())->toBe(1)
        ->and($category->printRoutes()->where('service_type', ServiceType::TableService)->first()->printer_id)
        ->toBe($printer->id);
});

it('persists the order of the destinations', function () {
    $category = Category::factory()->create();
    $printer = Printer::factory()->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm([
            'printRoutes_pickup' => [
                [
                    'document' => PrintJobType::PickupStub->value,
                    'destination' => PrintDestination::CashRegister->value,
                    'printer_id' => null,
                    'grouped' => false,
                ],
                [
                    'document' => PrintJobType::DepartmentTicket->value,
                    'destination' => PrintDestination::DepartmentPrinter->value,
                    'printer_id' => $printer->id,
                    'grouped' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $ordered = $category->printRoutes()
        ->where('service_type', ServiceType::Pickup)
        ->orderBy('position')
        ->pluck('destination');

    expect($ordered->first())->toBe(PrintDestination::CashRegister)
        ->and($ordered->last())->toBe(PrintDestination::DepartmentPrinter);
});

it('renders the drag handle to reorder destinations', function () {
    $category = Category::factory()->create();
    PrintRoute::factory()->count(2)->for($category)->toCashRegister()->create(['service_type' => ServiceType::Pickup]);

    $html = Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->html();

    expect($html)->toContain('x-sortable-handle');
});

it('supports multiple destinations for one service (double print)', function () {
    $category = Category::factory()->create();
    $printer = Printer::factory()->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm([
            'printRoutes_pickup' => [
                [
                    'document' => PrintJobType::PickupStub->value,
                    'destination' => PrintDestination::CashRegister->value,
                    'printer_id' => null,
                    'grouped' => false,
                ],
                [
                    'document' => PrintJobType::DepartmentTicket->value,
                    'destination' => PrintDestination::DepartmentPrinter->value,
                    'printer_id' => $printer->id,
                    'grouped' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($category->printRoutes()->where('service_type', ServiceType::Pickup)->count())->toBe(2);
});

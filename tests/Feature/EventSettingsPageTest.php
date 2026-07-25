<?php

use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Enums\ServiceType;
use App\Filament\Pages\ManageEventSettings;
use App\Models\Printer;
use App\Models\PrintRoute;
use App\Models\User;
use App\Settings\EventSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('mounts the event settings page', function () {
    Livewire::test(ManageEventSettings::class)->assertOk();
});

it('fills the form with the current settings', function () {
    $settings = app(EventSettings::class);
    $settings->eventName = 'Sagra di prova';
    $settings->coverCharge = 250;
    $settings->save();

    Livewire::test(ManageEventSettings::class)
        ->assertFormSet([
            'eventName' => 'Sagra di prova',
            'coverCharge' => '2.50',
        ]);
});

it('saves the event name and the cover charge in cents', function () {
    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => 'Venerdì, Sabato, Domenica',
            'coverCharge' => '1,50',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(EventSettings::class);

    expect($settings->eventName)->toBe('Venerdì, Sabato, Domenica')
        ->and($settings->coverCharge)->toBe(150);
});

it('saves the discount-applies-to-cover flag', function () {
    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => 'Sagra',
            'coverCharge' => '1,00',
            'discountAppliesToCover' => true,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(app(EventSettings::class)->discountAppliesToCover)->toBeTrue();
});

it('stores an uploaded receipt logo on the public disk', function () {
    Storage::fake('public');

    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => 'Sagra',
            'coverCharge' => '1,00',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 100),
        ])
        ->call('save')
        ->assertHasNoErrors();

    $logo = app(EventSettings::class)->logo;

    expect($logo)->not->toBeNull();
    Storage::disk('public')->assertExists($logo);
});

it('requires the event name', function () {
    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => '',
            'coverCharge' => '1,00',
        ])
        ->call('save')
        ->assertHasFormErrors(['eventName' => 'required']);
});

it('loads the existing covers routes into the Coperti tab', function () {
    $printer = Printer::factory()->create();
    PrintRoute::factory()->forCovers()->create([
        'service_type' => ServiceType::TableService,
        'destination' => PrintDestination::DepartmentPrinter,
        'printer_id' => $printer->id,
        'position' => 1,
    ]);

    $state = Livewire::test(ManageEventSettings::class)->get('data');
    $rows = array_values($state['coverRoutes_table_service']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['document'])->toBe(PrintJobType::DepartmentTicket->value)
        ->and($rows[0]['destination'])->toBe(PrintDestination::DepartmentPrinter->value)
        ->and($rows[0]['printer_id'])->toEqual($printer->id);
});

it('saves covers routes as standalone for_covers print routes', function () {
    $printer = Printer::factory()->create();

    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => 'Sagra',
            'coverCharge' => '2,00',
            'coverRoutes_table_service' => [
                ['document' => PrintJobType::DepartmentTicket->value, 'destination' => PrintDestination::DepartmentPrinter->value, 'printer_id' => $printer->id],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $route = PrintRoute::where('for_covers', true)->sole();

    expect($route->category_id)->toBeNull()
        ->and($route->service_type)->toBe(ServiceType::TableService)
        ->and($route->document)->toBe(PrintJobType::DepartmentTicket)
        ->and($route->printer_id)->toEqual($printer->id)
        ->and($route->position)->toBe(1);

    // The route keys are not persisted as settings.
    expect(app(EventSettings::class)->eventName)->toBe('Sagra');
});

it('replaces the existing covers routes on save', function () {
    PrintRoute::factory()->forCovers()->create(['service_type' => ServiceType::TableService]);

    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => 'Sagra',
            'coverCharge' => '1,00',
            'coverRoutes_table_service' => [],
            'coverRoutes_pickup' => [],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(PrintRoute::where('for_covers', true)->count())->toBe(0);
});

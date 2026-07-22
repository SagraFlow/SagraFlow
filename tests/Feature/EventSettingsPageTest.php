<?php

use App\Filament\Pages\ManageEventSettings;
use App\Models\User;
use App\Settings\EventSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('requires the event name', function () {
    Livewire::test(ManageEventSettings::class)
        ->fillForm([
            'eventName' => '',
            'coverCharge' => '1,00',
        ])
        ->call('save')
        ->assertHasFormErrors(['eventName' => 'required']);
});

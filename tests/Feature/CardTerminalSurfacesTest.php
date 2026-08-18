<?php

use App\Filament\Resources\CardTerminals\Pages\ManageCardTerminals;
use App\Filament\Resources\CashRegisters\Pages\ManageCashRegisters;
use App\Models\CardTerminal;
use App\Models\CashRegister;
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

it('lists the terminals with the register each one serves', function () {
    $terminal = CardTerminal::factory()->create(['name' => 'POS Bar', 'terminal_id' => '00099887']);
    CashRegister::factory()->create(['name' => 'Cassa Bar', 'card_terminal_id' => $terminal->id]);

    Livewire::test(ManageCardTerminals::class)
        ->assertCanSeeTableRecords([$terminal])
        ->assertSee('00099887')
        ->assertSee('Cassa Bar');
});

it('creates a terminal from the panel', function () {
    Livewire::test(ManageCardTerminals::class)
        ->callAction(TestAction::make('create'), data: [
            'name' => 'POS Cucina',
            'ip_address' => '192.168.1.50',
            'port' => 6000,
            'terminal_id' => '00123456',
            'active' => true,
        ])
        ->assertHasNoActionErrors();

    expect(CardTerminal::where('name', 'POS Cucina')->first()?->terminal_id)->toBe('00123456');
});

it('refuses a terminal id that is not eight digits', function () {
    Livewire::test(ManageCardTerminals::class)
        ->callAction(TestAction::make('create'), data: [
            'name' => 'POS Corto',
            'ip_address' => '192.168.1.51',
            'port' => 6000,
            'terminal_id' => '1234',
            'active' => true,
        ])
        ->assertHasActionErrors(['terminal_id']);

    expect(CardTerminal::where('name', 'POS Corto')->exists())->toBeFalse();
});

it('assigns a terminal to a register from the register form', function () {
    $terminal = CardTerminal::factory()->create();
    $register = CashRegister::factory()->create();

    Livewire::test(ManageCashRegisters::class)
        ->callAction(TestAction::make('edit')->table($register), data: [
            'name' => $register->name,
            'card_terminal_id' => $terminal->id,
            'active' => true,
        ])
        ->assertHasNoActionErrors();

    expect($register->fresh()->card_terminal_id)->toBe($terminal->id);
});

it('lets a second register share the same terminal', function () {
    $terminal = CardTerminal::factory()->create();
    CashRegister::factory()->create(['card_terminal_id' => $terminal->id]);
    $other = CashRegister::factory()->create();

    // Sharing is allowed on purpose: the second station waits for the terminal
    // to be free, which is a rule of the payment, not of the configuration.
    Livewire::test(ManageCashRegisters::class)
        ->callAction(TestAction::make('edit')->table($other), data: [
            'name' => $other->name,
            'card_terminal_id' => $terminal->id,
            'active' => true,
        ])
        ->assertHasNoActionErrors();

    expect($other->fresh()->card_terminal_id)->toBe($terminal->id)
        ->and($terminal->cashRegisters()->count())->toBe(2);
});

it('offers the probe on every terminal row', function () {
    $terminal = CardTerminal::factory()->create();

    Livewire::test(ManageCardTerminals::class)
        ->assertActionVisible(TestAction::make('probe')->table($terminal));
});

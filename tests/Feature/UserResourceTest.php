<?php

use App\Enums\UserRole;
use App\Exceptions\UserException;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('makes the first account an administrator, whoever asks for it', function () {
    // Filament's own make:filament-user writes a name, an email and a password
    // and knows nothing about roles: on a fresh install it would otherwise
    // create a cashier, say "you may now log in", and leave the panel shut.
    $first = User::create(['name' => 'Andrea', 'email' => 'andrea@sagra.test', 'password' => 'segretissima']);

    expect($first->role)->toBe(UserRole::Administrator);

    // Once one exists, the cautious default applies again.
    $second = User::create(['name' => 'Cassa 1', 'email' => 'cassa1@sagra.test', 'password' => 'segretissima']);

    expect($second->fresh()->role)->toBe(UserRole::Cashier);
});

it('closes the panel to a till account and opens it to an administrator', function () {
    $panel = Filament::getPanel('admin');

    expect(User::factory()->cashier()->create()->canAccessPanel($panel))->toBeFalse()
        ->and(User::factory()->create()->canAccessPanel($panel))->toBeTrue();
});

it('opens the queue dashboard to administrators and to nobody else', function () {
    // The gate shipped as an empty list of allowed email addresses, which outside
    // local shuts Horizon to everybody - the sort of thing discovered on the one
    // evening it is needed. Inside local Horizon waves the gate through, which is
    // what makes it openable while developing.
    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->cashier()->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::allows('viewHorizon'))->toBeFalse();

    // And the gate is what the dashboard's own guard asks, outside local.
    $this->actingAs(User::factory()->cashier()->create())
        ->get('/horizon')
        ->assertForbidden();
});

it('takes a till account that lands in the panel to the till instead', function () {
    // The sign-in form is the panel's, so this is the path a cashier walks every
    // time the tablet is logged out: without the redirect they meet a 403.
    $this->actingAs(User::factory()->cashier()->create())
        ->get('/admin')
        ->assertRedirect(route('pos'));
});

it('lets a till account work the till', function () {
    $this->actingAs(User::factory()->cashier()->create())
        ->get(route('pos'))
        ->assertOk();
});

it('creates an account with a role and a hashed password', function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('create'), data: [
            'name' => 'Cassa 1',
            'email' => 'cassa1@sagra.test',
            'role' => UserRole::Cashier->value,
            'password' => 'segretissima',
        ])
        ->assertHasNoActionErrors();

    $created = User::where('email', 'cassa1@sagra.test')->sole();

    expect($created->role)->toBe(UserRole::Cashier)
        ->and($created->password)->not->toBe('segretissima')
        ->and(Hash::check('segretissima', $created->password))->toBeTrue();
});

it('leaves the password alone when the field is left empty on edit', function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $cashier = User::factory()->cashier()->create(['password' => Hash::make('quella-di-prima')]);

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('edit')->table($cashier), data: [
            'name' => 'Cassa 2',
            'email' => $cashier->email,
            'role' => UserRole::Cashier->value,
            'password' => '',
        ])
        ->assertHasNoActionErrors();

    expect($cashier->fresh()->name)->toBe('Cassa 2')
        ->and(Hash::check('quella-di-prima', $cashier->fresh()->password))->toBeTrue();
});

it('refuses to delete the last administrator, whatever asks', function () {
    $admin = User::factory()->create();
    User::factory()->cashier()->create();

    // Guarded in the model, so a form, a command and a tinker session all get the
    // same answer: a sagra with a panel nobody can open is unrecoverable from
    // inside the app.
    expect(fn () => $admin->delete())->toThrow(UserException::class, 'Deve restare almeno un amministratore.');

    expect(User::whereKey($admin->id)->exists())->toBeTrue();
});

it('refuses to demote the last administrator', function () {
    $admin = User::factory()->create();

    expect(fn () => $admin->update(['role' => UserRole::Cashier]))
        ->toThrow(UserException::class, 'Deve restare almeno un amministratore.');

    expect($admin->fresh()->role)->toBe(UserRole::Administrator);
});

it('lets an administrator go once another one remains', function () {
    $first = User::factory()->create();
    User::factory()->create();

    $first->delete();

    expect(User::whereKey($first->id)->exists())->toBeFalse();
});

it('says so in the panel instead of breaking when the last administrator is deleted', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('delete')->table($admin))
        ->assertNotified();

    expect(User::whereKey($admin->id)->exists())->toBeTrue();
});

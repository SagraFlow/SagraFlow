<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets a cashier sign in and takes her to the till', function () {
    // The whole point of this form. Sent to the panel's own sign-in, this
    // account is told its password is wrong: Filament will not authenticate
    // anybody it would then have to keep out of the panel.
    $cashier = User::factory()->cashier()->create();

    Livewire::test('pages::login')
        ->set('email', $cashier->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('pos'));

    expect(auth()->id())->toBe($cashier->id);
});

it('lets an administrator in through the same door', function () {
    $administrator = User::factory()->create();

    Livewire::test('pages::login')
        ->set('email', $administrator->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        // She came to the counter, not to the panel.
        ->assertRedirect(route('pos'));

    expect(auth()->id())->toBe($administrator->id);
});

it('takes the operator where she was headed', function () {
    $cashier = User::factory()->cashier()->create();
    session()->put('url.intended', route('pos').'?tavolo=12');

    Livewire::test('pages::login')
        ->set('email', $cashier->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('pos').'?tavolo=12');
});

it('refuses a wrong password and signs nobody in', function () {
    $cashier = User::factory()->cashier()->create();

    Livewire::test('pages::login')
        ->set('email', $cashier->email)
        ->set('password', 'non-questa')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('makes a tablet wait after too many wrong tries', function () {
    $cashier = User::factory()->cashier()->create();

    $component = Livewire::test('pages::login')
        ->set('email', $cashier->email)
        ->set('password', 'non-questa');

    foreach (range(1, 5) as $ignored) {
        $component->call('login')->assertHasErrors('email');
    }

    // Even the right password is refused now: the wait is on the address and
    // the place it is being guessed from, not on the attempt being wrong.
    $component->set('password', 'password')->call('login')->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('sends a guest asking for the till to the sign-in', function () {
    $this->get(route('pos'))->assertRedirect(route('login'));
});

it('takes a tablet that is already signed in straight to the till', function () {
    $this->actingAs(User::factory()->cashier()->create())
        ->get(route('login'))
        ->assertRedirect(route('pos'));
});

it('still keeps a cashier out of the panel', function () {
    // The door being separate is what lets this stay as strict as it is.
    $this->actingAs(User::factory()->cashier()->create())
        ->get(route('filament.admin.resources.users.index'))
        ->assertRedirect(route('pos'));
});

<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The till's own door.
 *
 * Separate from the panel's sign-in on purpose. The panel is for administrators
 * and says so in `User::canAccessPanel()`, and Filament reads that during the
 * attempt itself: it refuses to authenticate anyone who could not open the
 * panel afterwards. Sent to that form, a cashier with the right password is
 * told her credentials are wrong, which is how a till account ends up unable to
 * sign in anywhere at all.
 *
 * This form asks who you are and takes you to the till, and knows nothing about
 * the panel.
 */
new #[Layout('components.layouts.app')] #[Title('Accedi')] class extends Component
{
    /** Wrong tries allowed on one address, from one place, before it waits. */
    public const MAX_ATTEMPTS = 5;

    public string $email = '';

    public string $password = '';

    public function login()
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Serve la mail.',
            'email.email' => 'Questa non sembra una mail.',
            'password.required' => 'Serve la password.',
        ]);

        $this->ensureIsNotRateLimited();

        // Remembered on purpose: the tablet at the counter stays signed in for
        // the evening, and a cashier with a queue in front of her must never be
        // asked for a password because a session quietly expired.
        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], remember: true)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Queste credenziali non risultano.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        // Wherever she was headed, or the till: an administrator who signs in
        // here came to the counter, not to the panel.
        return $this->redirectIntended(route('pos'), navigate: false);
    }

    /**
     * Guessing is slowed down where the guessing happens: per address and per
     * place, so one tablet locked out by a typo does not lock out the others.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Troppi tentativi. Riprova tra '.RateLimiter::availableIn($this->throttleKey()).' secondi.',
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}

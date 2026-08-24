<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Exceptions\UserException;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Who gets into the panel: administrators only. A till account signs in on a
     * tablet that stays logged in all evening, and one stray tap from there
     * would otherwise reach the prices, the printers and the open day.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdministrator();
    }

    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Administrator;
    }

    protected static function booted(): void
    {
        // Guarded in the model rather than in the panel, so no path - a form, a
        // command, a tinker session - can leave the sagra with a panel nobody
        // can open.
        static::updating(function (User $user): void {
            if ($user->isDirty('role') && ! $user->isAdministrator() && static::isLastAdministrator($user)) {
                throw new UserException('Deve restare almeno un amministratore.');
            }
        });

        static::deleting(function (User $user): void {
            if ($user->isAdministrator() && static::isLastAdministrator($user)) {
                throw new UserException('Deve restare almeno un amministratore.');
            }
        });
    }

    protected static function isLastAdministrator(User $user): bool
    {
        return ! static::query()
            ->where('role', UserRole::Administrator)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}

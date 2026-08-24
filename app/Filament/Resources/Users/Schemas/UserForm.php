<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    // A till account is named after the station, not a person:
                    // the tablet stays signed in and the volunteers take turns
                    // on it, so "Cassa 1" is what actually shows on an order.
                    ->helperText('Per un cassiere, il nome della postazione: "Cassa 1".')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->label('Ruolo')
                    ->options(UserRole::class)
                    ->default(UserRole::Cashier)
                    ->helperText('Il cassiere entra solo in cassa. L\'amministratore vede anche questo pannello.')
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    // Kept simple on purpose: these are shared tablets at a
                    // counter, not personal mailboxes, and a password nobody can
                    // type is a tablet that stays logged in forever.
                    ->helperText('Alla modifica, lascia vuoto per non cambiarla.')
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(8)
                    ->maxLength(255)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
            ]);
    }
}

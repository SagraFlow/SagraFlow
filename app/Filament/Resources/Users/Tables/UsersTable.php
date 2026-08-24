<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Exceptions\UserException;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Ruolo')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Creato')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Ruolo')
                    ->options(UserRole::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium),
                // The model refuses to leave the sagra without an administrator;
                // this turns that refusal into a message instead of a red page.
                DeleteAction::make()
                    ->action(function (User $record, DeleteAction $action): void {
                        try {
                            $record->delete();
                        } catch (UserException $e) {
                            Notification::make()->danger()->title('Account non eliminato')->body($e->getMessage())->send();

                            $action->cancel();
                        }
                    }),
            ])
            // No bulk delete: the accounts are a handful, and a bulk action is
            // how you remove the last administrator by accident.
            ->toolbarActions([]);
    }
}

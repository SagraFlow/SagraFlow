<?php

namespace App\Filament\Resources\CardTerminals\Pages;

use App\Filament\Resources\CardTerminals\CardTerminalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageCardTerminals extends ManageRecords
{
    protected static string $resource = CardTerminalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth(Width::Medium),
        ];
    }
}

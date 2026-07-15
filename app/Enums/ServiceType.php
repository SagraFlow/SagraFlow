<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ServiceType: string implements HasIcon, HasLabel
{
    case TableService = 'table_service';
    case Pickup = 'pickup';
    case TakeAway = 'take_away';

    public function getLabel(): string
    {
        return match ($this) {
            self::TableService => 'Servizio al tavolo',
            self::Pickup => 'Ritiro',
            self::TakeAway => 'Asporto',
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::TableService => Heroicon::OutlinedUserGroup,
            self::Pickup => Heroicon::OutlinedShoppingBag,
            self::TakeAway => Heroicon::OutlinedTruck,
        };
    }
}

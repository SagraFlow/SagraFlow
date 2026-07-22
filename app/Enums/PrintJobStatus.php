<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PrintJobStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Printed = 'printed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'In coda',
            self::Printed => 'Stampato',
            self::Failed => 'Fallito',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Printed => 'success',
            self::Failed => 'danger',
        };
    }
}

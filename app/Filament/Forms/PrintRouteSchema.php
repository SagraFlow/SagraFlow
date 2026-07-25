<?php

namespace App\Filament\Forms;

use App\Enums\PrintDestination;
use App\Enums\PrintJobType;
use App\Models\Printer;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Shared form fields for a print route, so the category comanda routes and the
 * covers routes stay in sync. The route-type-specific parts (the category's
 * "grouped" toggle, the covers layout tweaks) live with each caller.
 */
class PrintRouteSchema
{
    public static function document(): Select
    {
        return Select::make('document')
            ->label('Tipo di stampa')
            ->options(PrintJobType::routableOptions())
            ->required()
            ->default(PrintJobType::DepartmentTicket->value);
    }

    public static function destination(): Select
    {
        return Select::make('destination')
            ->label('Destinazione')
            ->options(PrintDestination::class)
            ->required()
            ->live()
            ->default(PrintDestination::DepartmentPrinter);
    }

    public static function printer(): Select
    {
        return Select::make('printer_id')
            ->label('Stampante')
            ->options(fn (): array => Printer::query()
                ->active()
                ->notAssignedToCashRegister()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->searchable()
            ->visible(self::requiresPrinter(...))
            ->required(self::requiresPrinter(...));
    }

    /**
     * Whether the current row's destination is a fixed department printer, which
     * is the only destination that needs a printer chosen up front.
     */
    public static function requiresPrinter(Get $get): bool
    {
        $destination = $get('destination');
        $destination = $destination instanceof PrintDestination
            ? $destination
            : PrintDestination::tryFrom((string) $destination);

        return $destination?->requiresPrinter() ?? false;
    }
}

<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\PrintDestination;
use App\Enums\ServiceType;
use App\Models\Category;
use App\Models\Printer;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Toggle::make('active')
                    ->label('Attiva')
                    ->default(true),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100),
                Tabs::make('Destinazioni di stampa')
                    ->tabs(array_map(self::serviceTab(...), ServiceType::cases()))
                    ->columnSpanFull(),
            ]);
    }

    protected static function serviceTab(ServiceType $type): Tab
    {
        return Tab::make($type->getLabel())
            ->icon($type->getIcon())
            ->badge(fn (?Category $record): int => (int) $record?->printRoutes()->where('service_type', $type->value)->count())
            ->schema([
                self::destinationsRepeater($type),
            ]);
    }

    protected static function destinationsRepeater(ServiceType $type): Repeater
    {
        $requiresPrinter = function (Get $get): bool {
            $destination = $get('destination');
            $destination = $destination instanceof PrintDestination
                ? $destination
                : PrintDestination::tryFrom((string) $destination);

            return $destination?->requiresPrinter() ?? false;
        };

        return Repeater::make("printRoutes_{$type->value}")
            ->hiddenLabel()
            ->defaultItems(0)
            ->relationship('printRoutes', modifyQueryUsing: fn (Builder $query): Builder => $query->where('service_type', $type->value))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'service_type' => $type->value])
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => [...$data, 'service_type' => $type->value])
            // `relationship()` resets reorderable to false, so re-enable it afterwards.
            ->reorderable()
            ->orderColumn('position')
            ->addActionLabel('Aggiungi destinazione')
            ->columns(2)
            ->schema([
                Select::make('destination')
                    ->label('Destinazione')
                    ->options(PrintDestination::class)
                    ->required()
                    ->live()
                    ->default(PrintDestination::DepartmentPrinter),
                Select::make('printer_id')
                    ->label('Stampante')
                    ->options(fn (): array => Printer::query()
                        ->active()
                        ->notAssignedToCashRegister()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->visible($requiresPrinter)
                    ->required($requiresPrinter),
                Toggle::make('grouped')
                    ->label('Raggruppa i prodotti')
                    ->helperText('Disattiva per stampare un tagliandino singolo per unità.')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\ServiceType;
use App\Filament\Forms\PrintRouteSchema;
use App\Models\Category;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                PrintRouteSchema::document(),
                Toggle::make('grouped')
                    ->label('Raggruppa i prodotti')
                    ->helperText('Disattiva per stampare un tagliandino singolo per unità.')
                    ->default(true)
                    ->inline(false),
                PrintRouteSchema::destination(),
                PrintRouteSchema::printer(),
            ]);
    }
}

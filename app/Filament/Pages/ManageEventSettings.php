<?php

namespace App\Filament\Pages;

use App\Enums\ServiceType;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Forms\PrintRouteSchema;
use App\Models\PrintRoute;
use App\Settings\EventSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageEventSettings extends SettingsPage
{
    protected static string $settings = EventSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configurazione';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Impostazioni';

    /**
     * Covers routes pulled off the form state before the settings are saved, so
     * they can be synced to the print_routes table in afterSave().
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $coverRouteState = [];

    public static function getNavigationLabel(): string
    {
        return 'Impostazioni';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Generale')
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->schema([
                                TextInput::make('eventName')
                                    ->label('Nome evento')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('timezone')
                                    ->label('Fuso orario')
                                    ->helperText('Usato per mostrare e stampare gli orari (salvati in UTC).')
                                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                                    ->searchable()
                                    ->required(),
                                FileUpload::make('logo')
                                    ->label('Logo scontrino')
                                    ->image()
                                    ->disk('public')
                                    ->directory('logos')
                                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                                    ->maxSize(1024)
                                    ->imageResizeMode('contain')
                                    ->imageResizeTargetWidth('600')
                                    ->imageResizeTargetHeight('600')
                                    ->imageResizeUpscale(false)
                                    ->imageEditor()
                                    ->helperText('PNG/JPEG, max 1MB. Ridimensionato a max 600px in fase di caricamento e stampato monocromatico in cima allo scontrino.'),
                            ]),
                        Tab::make('Coperti')
                            ->icon(Heroicon::OutlinedUsers)
                            ->schema([
                                MoneyInput::make('coverCharge')
                                    ->label('Costo del coperto')
                                    ->required(),
                                Toggle::make('discountAppliesToCover')
                                    ->label('Applica lo sconto anche al coperto')
                                    ->helperText('Se attivo, lo sconto dell\'ordine riduce anche il costo del coperto.'),
                                Tabs::make('Destinazioni di stampa dei coperti')
                                    ->tabs(array_map($this->coverRouteTab(...), ServiceType::cases()))
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (ServiceType::cases() as $type) {
            $data[$this->coverRoutesKey($type)] = PrintRoute::query()
                ->where('for_covers', true)
                ->where('service_type', $type->value)
                ->orderBy('position')
                ->get()
                ->map(fn (PrintRoute $route): array => [
                    'document' => $route->document->value,
                    'destination' => $route->destination->value,
                    'printer_id' => $route->printer_id,
                ])
                ->all();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->coverRouteState = [];

        foreach (ServiceType::cases() as $type) {
            $key = $this->coverRoutesKey($type);
            $this->coverRouteState[$type->value] = $data[$key] ?? [];
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        foreach (ServiceType::cases() as $type) {
            PrintRoute::query()
                ->where('for_covers', true)
                ->where('service_type', $type->value)
                ->delete();

            foreach ($this->coverRouteState[$type->value] ?? [] as $position => $row) {
                PrintRoute::create([
                    'category_id' => null,
                    'for_covers' => true,
                    'service_type' => $type->value,
                    'document' => $row['document'],
                    'destination' => $row['destination'],
                    'printer_id' => $row['printer_id'] ?? null,
                    'grouped' => true,
                    'position' => $position + 1,
                ]);
            }
        }
    }

    protected function coverRouteTab(ServiceType $type): Tab
    {
        return Tab::make($type->getLabel())
            ->icon($type->getIcon())
            ->schema([
                $this->coverRoutesRepeater($type),
            ]);
    }

    protected function coverRoutesRepeater(ServiceType $type): Repeater
    {
        return Repeater::make($this->coverRoutesKey($type))
            ->hiddenLabel()
            ->defaultItems(0)
            ->reorderable()
            ->addActionLabel('Aggiungi destinazione')
            ->columns(2)
            ->schema([
                PrintRouteSchema::document(),
                // Start a new row so the destination/printer dropdowns sit in the
                // same columns as the category routes (which have the "grouped"
                // toggle in the top-right slot the covers lack).
                PrintRouteSchema::destination()->columnStart(1),
                PrintRouteSchema::printer(),
            ]);
    }

    protected function coverRoutesKey(ServiceType $type): string
    {
        return "coverRoutes_{$type->value}";
    }
}

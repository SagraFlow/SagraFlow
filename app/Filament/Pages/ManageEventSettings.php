<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\MoneyInput;
use App\Settings\EventSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
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

    public static function getNavigationLabel(): string
    {
        return 'Impostazioni';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('eventName')
                    ->label('Nome evento')
                    ->required()
                    ->maxLength(255),
                MoneyInput::make('coverCharge')
                    ->label('Costo del coperto')
                    ->required(),
                Toggle::make('discountAppliesToCover')
                    ->label('Applica lo sconto anche al coperto')
                    ->helperText('Se attivo, lo sconto dell\'ordine riduce anche il costo del coperto.'),
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
            ]);
    }
}

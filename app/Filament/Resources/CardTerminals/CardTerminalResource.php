<?php

namespace App\Filament\Resources\CardTerminals;

use App\Filament\Resources\CardTerminals\Pages\ManageCardTerminals;
use App\Filament\Resources\CardTerminals\Schemas\CardTerminalForm;
use App\Filament\Resources\CardTerminals\Tables\CardTerminalsTable;
use App\Models\CardTerminal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CardTerminalResource extends Resource
{
    protected static ?string $model = CardTerminal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Configurazione';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'terminale POS';

    protected static ?string $pluralModelLabel = 'terminali POS';

    public static function form(Schema $schema): Schema
    {
        return CardTerminalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardTerminalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCardTerminals::route('/'),
        ];
    }
}

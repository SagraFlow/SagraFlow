<?php

namespace App\Filament\Resources\Food\RelationManagers;

use App\Filament\Tables\Columns\MoneyColumn;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IngredientsRelationManager extends RelationManager
{
    protected static string $relationship = 'ingredients';

    // "food" is uncountable, so Filament mis-guesses the inverse relationship as
    // Ingredient::food(); point it at the real one instead.
    protected static ?string $inverseRelationship = 'foods';

    protected static ?string $title = 'Ricetta';

    public function form(Schema $schema): Schema
    {
        return $schema->components(self::pivotFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Ingrediente')
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('surcharge')
                    ->label('Supplemento'),
                TextColumn::make('quantity')
                    ->label('Dose')
                    ->numeric(),
                TextColumn::make('min_quantity')
                    ->label('Min')
                    ->numeric(),
                TextColumn::make('max_quantity')
                    ->label('Max')
                    ->numeric(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        ...self::pivotFields(),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Pivot fields describing an ingredient's role in the recipe.
     *
     * @return array<int, TextInput>
     */
    protected static function pivotFields(): array
    {
        return [
            TextInput::make('quantity')
                ->label('Dose base')
                ->helperText('Quantità normalmente presente nel piatto.')
                ->numeric()
                ->live(onBlur: true)
                ->default(1)
                ->required()
                ->minValue(fn (Get $get): int => (int) ($get('min_quantity') ?? 0))
                ->maxValue(fn (Get $get): ?int => filled($get('max_quantity')) ? (int) $get('max_quantity') : null),
            TextInput::make('min_quantity')
                ->label('Quantità minima')
                ->helperText('0 = il cliente può rimuoverlo.')
                ->numeric()
                ->live(onBlur: true)
                ->default(1)
                ->required()
                ->minValue(0)
                ->maxValue(fn (Get $get): ?int => filled($get('quantity')) ? (int) $get('quantity') : null),
            TextInput::make('max_quantity')
                ->label('Quantità massima')
                ->helperText('Maggiore della dose base = raddoppiabile.')
                ->numeric()
                ->live(onBlur: true)
                ->default(1)
                ->required()
                ->minValue(fn (Get $get): int => (int) ($get('quantity') ?? 1)),
        ];
    }
}

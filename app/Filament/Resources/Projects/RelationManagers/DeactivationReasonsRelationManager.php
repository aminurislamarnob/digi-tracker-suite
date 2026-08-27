<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Deactivation;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The reasons this project offers when somebody deactivates.
 *
 * Seeded with the SDK's seven so a stock integration works untouched. The
 * `reason_id` is the key and the label is only presentation: rewording a
 * reason keeps every deactivation already recorded against it, which is
 * why the id is not editable after creation.
 */
class DeactivationReasonsRelationManager extends RelationManager
{
    protected static string $relationship = 'deactivationReasons';

    protected static ?string $title = 'Deactivation reasons';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('reason_id')
                ->required()
                ->maxLength(64)
                ->alphaDash()
                /*
                 * Immutable once it exists. Historical deactivations store
                 * the id, not the label -- changing it would orphan every
                 * event already recorded against it, and they would quietly
                 * start rendering as their raw id instead.
                 */
                ->disabledOn('edit')
                ->helperText('Sent by the SDK. Cannot change once deactivations have been recorded against it.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('project_id', $this->getOwnerRecord()->id)),

            TextInput::make('label')->required()->maxLength(255),

            TextInput::make('placeholder')
                ->maxLength(255)
                ->helperText('Prompt shown in the comment box when this reason is picked.'),

            TextInput::make('sort_order')->numeric()->default(0),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Switching off hides it from the dialog. Past deactivations keep their label.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('label')->searchable(),
                TextColumn::make('reason_id')->color('gray')->searchable(),

                TextColumn::make('used')
                    ->label('Times chosen')
                    ->numeric()
                    // Live count, so an author can see which reasons are
                    // earning their place before rewording or removing one.
                    ->state(fn ($record) => Deactivation::query()
                        ->where('project_id', $record->project_id)
                        ->where('reason_id', $record->reason_id)
                        ->count()),

                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Deactivations already recorded against this reason keep it, and will show the raw id once the label is gone. Switching it off instead keeps the label.'),
            ])
            ->defaultSort('sort_order');
    }
}

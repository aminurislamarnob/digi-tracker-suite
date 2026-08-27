<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectMetaField;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The whitelist for the SDK's extra[] bag.
 *
 * add_extra() lets an integrator send anything at all, so without this an
 * unbounded, untyped bag of keys reaches the database -- and it is the
 * likeliest route by which personal data nobody asked for would arrive.
 * Keys registered here are kept and cast; everything else is dropped at
 * ingest, silently, because the site never reads our response.
 */
class MetaFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'metaFields';

    protected static ?string $title = 'Metadata whitelist';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->required()
                ->maxLength(64)
                ->alphaDash()
                ->helperText('Must match the array key sent by add_extra() exactly.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('project_id', $this->getOwnerRecord()->id)),

            Select::make('datatype')
                ->options(array_combine(ProjectMetaField::DATATYPES, array_map('ucfirst', ProjectMetaField::DATATYPES)))
                ->default(ProjectMetaField::TYPE_STRING)
                ->required()
                ->helperText('Everything arrives as a string; this is what it is cast to before storage.'),

            TextInput::make('label')
                ->maxLength(255)
                ->helperText('Shown on charts. Defaults to the key.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->columns([
                TextColumn::make('key')->searchable(),
                TextColumn::make('datatype')->badge(),
                TextColumn::make('label')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    /*
                     * Removing a key stops new payloads carrying it. Values
                     * already stored in site_reports.extra stay, because they
                     * were collected under a whitelist that included it and
                     * rewriting history to match a later decision would make
                     * the archive disagree with what actually happened.
                     */
                    ->modalDescription('New payloads will drop this key. Values already recorded stay as they are.'),
            ])
            ->emptyStateHeading('No metadata keys registered')
            ->emptyStateDescription('Until a key is registered here, everything sent through add_extra() is discarded.')
            ->defaultSort('key');
    }
}

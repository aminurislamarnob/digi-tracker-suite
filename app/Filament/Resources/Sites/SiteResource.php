<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Models\Project;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Read-only, and not by omission.
 *
 * Every row here is something a site told us. Editing one would mean the
 * dashboard disagreed with the telemetry that produced it, and the next
 * heartbeat would overwrite the edit anyway.
 */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'canonical_url';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Site')->columns(3)->schema([
                TextEntry::make('canonical_url')->label('URL'),
                TextEntry::make('name')->label('Title')->placeholder('—'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => ucfirst($state))->color(fn (string $state) => match ($state) {
                    Site::STATUS_ACTIVE => 'success',
                    Site::STATUS_DEACTIVATED => 'danger',
                    default => 'warning',
                }),
                TextEntry::make('project.name'),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('ip')->label('IP address')->placeholder('—'),
            ]),

            Section::make('Environment')->columns(3)->schema([
                TextEntry::make('current_version')->label('Plugin version')->placeholder('—'),
                TextEntry::make('wp_version')->label('WordPress')->placeholder('—'),
                TextEntry::make('php_version')->label('PHP')->placeholder('—'),
            ]),

            Section::make('Timeline')->columns(3)->schema([
                TextEntry::make('first_seen_at')->dateTime()->placeholder('—'),
                TextEntry::make('last_seen_at')->dateTime()->placeholder('—'),
                TextEntry::make('deactivated_at')->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('canonical_url')
                    ->label('Site')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('project.name')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        Site::STATUS_ACTIVE => 'success',
                        Site::STATUS_DEACTIVATED => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('current_version')->label('Version')->sortable(),
                TextColumn::make('wp_version')->label('WP')->sortable()->toggleable(),
                TextColumn::make('php_version')->label('PHP')->sortable()->toggleable(),
                TextColumn::make('country')->placeholder('—')->sortable()->toggleable(),

                IconColumn::make('is_local')
                    ->label('Local')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),

                SelectFilter::make('status')->options([
                    Site::STATUS_ACTIVE => 'Active',
                    Site::STATUS_INACTIVE => 'Inactive',
                    Site::STATUS_DEACTIVATED => 'Deactivated',
                ]),

                SelectFilter::make('current_version')
                    ->label('Plugin version')
                    // Only offer values this account's data actually contains.
                    ->options(fn () => Site::query()
                        ->whereNotNull('current_version')
                        ->distinct()
                        ->orderByDesc('current_version')
                        ->pluck('current_version', 'current_version')),

                SelectFilter::make('country')
                    ->options(fn () => Site::query()
                        ->whereNotNull('country')
                        ->distinct()
                        ->orderBy('country')
                        ->pluck('country', 'country')),

                TernaryFilter::make('is_local')
                    ->label('Local installs')
                    ->placeholder('Excluded')
                    ->trueLabel('Only local')
                    ->falseLabel('Excluded')
                    // A local install is somebody's laptop, and counting it
                    // would inflate the only number anyone ever quotes.
                    ->default(false),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'view' => ViewSite::route('/{record}'),
        ];
    }
}

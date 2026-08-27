<?php

namespace App\Filament\Resources\Deactivations;

use App\Filament\Resources\Deactivations\Pages\ListDeactivations;
use App\Models\Deactivation;
use App\Models\DeactivationReason;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Churn, and the only feedback this platform ever receives in the user's
 * own words. Every other screen is a count.
 *
 * Append-only: a site that leaves twice has churned twice and both events
 * are real, so nothing here is ever edited or deduplicated.
 */
class DeactivationResource extends Resource
{
    protected static ?string $model = Deactivation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->sortable(),

                TextColumn::make('reason_id')
                    ->label('Reason')
                    ->badge()
                    ->color('danger')
                    // Resolved through the project's own list: reason_id is
                    // only unique per project, and an author may have reworded
                    // or removed the reason since it was recorded.
                    ->formatStateUsing(fn (Deactivation $record) => $record->reasonLabel() ?? 'Not given'),

                TextColumn::make('reason_info')
                    ->label('What they said')
                    ->wrap()
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('site.canonical_url')->label('Site')->placeholder('—')->toggleable(),
                TextColumn::make('project.name')->sortable()->toggleable(),
                TextColumn::make('project_version')->label('Version')->sortable()->toggleable(),
                TextColumn::make('theme_name')->label('Theme')->placeholder('—')->toggleable(),

                TextColumn::make('reactivated_at')
                    ->label('Came back')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),

                SelectFilter::make('reason_id')
                    ->label('Reason')
                    ->options(fn () => DeactivationReason::query()
                        ->orderBy('sort_order')
                        ->pluck('label', 'reason_id')),

                Filter::make('with_comment')
                    ->label('Only those who wrote something')
                    ->query(fn (Builder $query) => $query->whereNotNull('reason_info')),

                Filter::make('still_gone')
                    ->label('Have not come back')
                    ->query(fn (Builder $query) => $query->whereNull('reactivated_at')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeactivations::route('/'),
        ];
    }
}

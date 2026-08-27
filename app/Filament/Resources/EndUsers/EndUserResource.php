<?php

namespace App\Filament\Resources\EndUsers;

use App\Filament\Resources\EndUsers\Pages\ListEndUsers;
use App\Models\EndUser;
use App\Models\Project;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * People, not sites.
 *
 * Email, first name and last name are encrypted at rest, which shapes this
 * whole screen: none of those columns can be sorted or searched with a
 * LIKE. Email is reachable through a keyed blind index and therefore by
 * exact match only -- a deliberate limit, and the difference between
 * answering a support ticket and browsing a mailing list.
 */
class EndUserResource extends Resource
{
    protected static ?string $model = EndUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'End users';

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
                TextColumn::make('full_name')
                    ->label('Name')
                    ->state(fn (EndUser $record) => trim($record->first_name.' '.$record->last_name) ?: null)
                    ->placeholder('—'),

                // Not searchable(): the column holds ciphertext, so a LIKE
                // would match nothing and quietly imply the person is absent.
                TextColumn::make('email')->copyable(),

                TextColumn::make('project.name')->sortable()->toggleable(),
                TextColumn::make('sites_count')->label('Sites')->counts('sites')->sortable(),

                IconColumn::make('marketing_consent_at')
                    ->label('Marketing consent')
                    ->boolean()
                    ->getStateUsing(fn (EndUser $record) => $record->hasMarketingConsent()),

                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),

                Filter::make('email')
                    ->schema([
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->helperText('Encrypted at rest — whole addresses only.'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['email'] ?? null),
                        fn (Builder $query) => $query->where('email_index', EndUser::indexFor($data['email'])),
                    ))
                    ->indicateUsing(fn (array $data) => filled($data['email'] ?? null) ? 'Email: '.$data['email'] : null),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEndUsers::route('/'),
        ];
    }
}

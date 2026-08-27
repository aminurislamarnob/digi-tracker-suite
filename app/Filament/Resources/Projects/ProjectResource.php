<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\DailyStat;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->helperText('Used in dashboard URLs. Match the wordpress.org slug.'),

                Select::make('type')
                    ->options(['plugin' => 'Plugin', 'theme' => 'Theme', 'bundle' => 'Bundle'])
                    ->default('plugin')
                    ->required(),

                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Switching this off makes ingest reject the hash with a 404.'),

                TextInput::make('homepage_url')->url()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->rows(3)->columnSpanFull(),
            ]),

            /*
             * Shown, never editable. The hash is the routing key baked into
             * every installed copy of the plugin: changing it would orphan
             * every site already reporting, permanently and silently.
             */
            Section::make('Integration')
                ->description('The hash the SDK sends. It cannot change once a release is out.')
                ->hiddenOn('create')
                ->schema([
                    TextInput::make('hash')
                        ->label('Project hash')
                        ->disabled()
                        ->dehydrated(false)
                        ->copyable(),
                ]),
        ]);
    }

    /**
     * The view page needs its own schema, or Filament falls back to the
     * form -- and a read-only page rendering editable-looking inputs
     * invites somebody to type into a field that will never save.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Project')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('type')->badge(),
                TextEntry::make('homepage_url')->label('Homepage')->url(fn ($state) => $state)->placeholder('--'),
                TextEntry::make('is_active')
                    ->label('Accepting telemetry')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                TextEntry::make('created_at')->dateTime(),
            ]),

            Section::make('Integration')
                ->description('The routing key the SDK sends with every request. Baked into each release, so it can never change.')
                ->schema([
                    TextEntry::make('hash')->label('Project hash')->copyable(),
                ]),

            Section::make()->schema([
                TextEntry::make('description')->hiddenLabel()->placeholder('No description.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->color('gray')->searchable(),

                TextColumn::make('tracked_installs')
                    ->label('Tracked installs')
                    ->numeric()
                    // Read from the nightly rollup, never from site_reports:
                    // aggregating JSON history on a list page is exactly the
                    // query that makes a MySQL dashboard feel broken.
                    ->state(fn (Project $record) => DailyStat::query()
                        ->where('project_id', $record->id)
                        ->orderByDesc('date')
                        ->value('active_installs') ?? 0),

                TextColumn::make('sites_count')->label('Sites')->counts('sites')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}

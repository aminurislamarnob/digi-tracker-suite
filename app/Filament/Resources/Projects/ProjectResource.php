<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectReports;
use App\Filament\Resources\Projects\Pages\ProjectRepository;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\DeactivationReasonsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\MetaFieldsRelationManager;
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
use Filament\Resources\Pages\Page;
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
                    ->helperText('Used in dashboard URLs.'),

                /*
                 * Separate from `slug` because the two are not ours to
                 * conflate. Ours is free to change; this one belongs to
                 * wordpress.org and is the key every public endpoint is
                 * addressed by. Leaving it empty is a valid state: an
                 * unpublished plugin still collects telemetry, it simply
                 * has no public half to compare against.
                 */
                TextInput::make('wporg_slug')
                    ->label('wordpress.org slug')
                    ->maxLength(255)
                    ->alphaDash()
                    ->helperText('From the plugin page URL. Leave empty if it is not published.'),

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
             * Mail leaves from our authenticated domain, because a platform
             * cannot inherit each customer's DKIM. What is configurable is
             * what the recipient sees -- and where a reply lands.
             */
            Section::make('Email')
                ->description('Every send is off until switched on here. Nothing is sent for demo projects.')
                ->hiddenOn('create')
                ->columns(2)
                ->schema([
                    TextInput::make('from_name')
                        ->label('From name')
                        ->maxLength(255)
                        ->placeholder(fn ($record) => $record?->name)
                        ->helperText('Shown as the sender. Defaults to the project name.'),

                    TextInput::make('reply_to')
                        ->label('Reply-to')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Where a reply from a user lands.'),

                    TextInput::make('support_email')
                        ->label('Support inbox')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Where forwarded deactivations go.'),

                    Textarea::make('email_footer')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->helperText('Appended to messages sent to your users.'),

                    Toggle::make('replies_to_deactivations')
                        ->label('Reply to feedback')
                        /*
                         * The restriction is the reason this is defensible.
                         * Replying to feedback somebody chose to write is
                         * expected; mailing everyone who dismissed a dialog
                         * would treat telemetry consent as permission to
                         * correspond, which it is not.
                         */
                        ->helperText('Only to people who actually wrote a comment, once each, ever.'),

                    Toggle::make('forwards_deactivations')
                        ->label('Forward deactivations')
                        ->helperText('A copy of every deactivation to the support inbox.'),

                    Toggle::make('sends_weekly_digest')
                        ->label('Weekly digest')
                        ->helperText('Monday mornings, to everyone on the account.'),
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

    /**
     * The editors live under the project, because both are project-scoped:
     * a metadata key and a reason id are only unique per project.
     */
    public static function getRelations(): array
    {
        return [
            MetaFieldsRelationManager::class,
            DeactivationReasonsRelationManager::class,
        ];
    }

    /**
     * The tabs across the top of a single project.
     *
     * Without this the Reports and Repository pages are routable but
     * unreachable -- registered in getPages(), reachable only by typing the
     * URL. A page nobody can find is a page nobody uses.
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewProject::class,
            ProjectReports::class,
            ProjectRepository::class,
            EditProject::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'reports' => ProjectReports::route('/{record}/reports'),
            'repository' => ProjectRepository::route('/{record}/repository'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}

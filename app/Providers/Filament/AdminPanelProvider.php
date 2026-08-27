<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetCurrentAccount;
use App\Models\Account;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Digi Tracker Suite')
            ->colors([
                'primary' => Color::Indigo,
            ])

            /*
             * The account is the tenant, and therefore the security boundary.
             *
             * Filament scopes every resource query through the `account`
             * relationship, which lines up exactly with the account_id column
             * the ingest pipeline already stamps on every telemetry row. The
             * slug rather than the id keeps sequential ids out of URLs, where
             * they would leak how many accounts exist.
             *
             * There is deliberately no tenant registration page: accounts are
             * created by hand until the success test decides whether this
             * becomes a product.
             */
            ->tenant(Account::class, slugAttribute: 'slug', ownershipRelationship: 'account')

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])

            /*
             * Mirror Filament's tenant into CurrentAccount so the global scope
             * on every telemetry model bites here too.
             *
             * Belt and braces on purpose. Filament's own scoping only covers
             * queries it builds; anything a widget or an action queries
             * directly would otherwise run unscoped. Persistent so it survives
             * Livewire's subsequent requests, which do not re-run the panel
             * middleware stack.
             */
            ->tenantMiddleware([
                SetCurrentAccount::class,
            ], isPersistent: true);
    }
}

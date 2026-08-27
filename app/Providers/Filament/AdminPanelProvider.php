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
use Filament\View\PanelsRenderHook;
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

            /*
             * brandName stays set even though a logo is shown: Filament uses
             * it for the <title>, and it is the alt text if the image fails.
             */
            ->brandName('Digi Tracker Suite')

            /*
             * Two files, because the wordmark is set in near-black and would
             * disappear against Filament's dark surfaces. The dark variant
             * carries the same mark with the wordmark reversed to white.
             *
             * Filament shows this on the sign-in screen and in the sidebar
             * header, so both are branded from these two lines.
             */
            ->brandLogo(fn (): string => asset('images/pluginizelab-logo.png'))
            ->darkModeBrandLogo(fn (): string => asset('images/pluginizelab-logo-dark.png'))

            /*
             * The source is 840x175, so height drives the width. 2rem keeps
             * the wordmark legible without crowding the sidebar.
             */
            ->brandLogoHeight('2rem')

            /*
             * Cropped from the mark rather than the whole wordmark: at 4.8:1
             * a scaled-down logo is an illegible smear in a browser tab.
             */
            ->favicon(fn (): string => asset('images/favicon-32.png'))

            /*
             * Sampled from the logo's mark (#195CE3) rather than left on
             * Indigo, which sits noticeably purple next to it -- the buttons
             * would have quietly disagreed with the brand on every screen.
             */
            ->colors([
                'primary' => Color::hex('#195CE3'),
            ])

            /*
             * A custom theme, so Tailwind utilities work in our own Blade
             * views. Filament ships a pre-compiled stylesheet that contains
             * only the classes its own components use; without this, utility
             * classes written in a custom page silently do nothing and the
             * layout collapses into stacked prose.
             */
            ->viteTheme('resources/css/filament/admin/theme.css')

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
            ], isPersistent: true)

            /*
             * Above the topbar, on every page, undismissable, for as long as
             * the account holds invented data. Demo numbers that look measured
             * are worse than no numbers -- somebody eventually quotes one.
             */
            ->renderHook(
                PanelsRenderHook::TOPBAR_BEFORE,
                fn () => view('filament.demo-banner'),
            );
    }
}

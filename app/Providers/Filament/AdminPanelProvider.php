<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Register;
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

            /*
             * Sign in, sign up, and the way back in after forgetting.
             *
             * Filament ships all three; what is ours is the registration
             * page, which has to create an account alongside the user --
             * see App\Filament\Pages\Auth\Register for why signing up
             * without one lands somebody straight on a refusal.
             *
             * Registration is behind a config flag because it creates a
             * tenant, so leaving it open on a public host lets a stranger
             * create an organisation inside the platform. Password reset is
             * not gated: locking an existing user out of their own account
             * has no upside.
             *
             * Not here, and deliberately not half-wired: email verification.
             * It needs User to implement MustVerifyEmail and a mailer that
             * actually reaches people. With MAIL_MAILER=log it would lock
             * every new user out of the panel they just signed up for, and a
             * switch that cannot be turned on is worse than no switch.
             */
            ->login()
            ->passwordReset()
            ->registration(config('telemetry.auth.registration') ? Register::class : null)

            /*
             * Client-side navigation once signed in.
             *
             * This is Livewire's wire:navigate, which Filament applies to
             * every panel link: the next page arrives over XHR and replaces
             * the body while the head is left alone, so stylesheets, scripts
             * and Alpine's state survive the move. Chart.js in particular is
             * parsed once per session rather than on every click, which is
             * most of what makes the dashboard feel quick.
             *
             * Prefetching is deliberately left off. It fetches on hover, and
             * the repository dashboard fetches wordpress.org on render -- so
             * hovering the tab would put real requests on a public API we
             * are a guest on, for a page nobody has decided to open yet.
             */
            ->spa()

            /*
             * The auth pages stay ordinary full page loads.
             *
             * Logging in and out is where the session identity changes, and
             * swapping a body into a document that was rendered for somebody
             * else is a class of bug worth not having: a stale CSRF token, a
             * tenant menu belonging to the previous user, a flash of a panel
             * the browser should already have forgotten.
             *
             * It costs nothing. These are entry and exit points, reached by
             * typing a URL or by a redirect, both of which are full loads
             * either way.
             */
            ->spaUrlExceptions(fn (): array => [
                url('/admin/login'),
                url('/admin/logout'),
                url('/admin/register'),
                url('/admin/password-reset/*'),
            ])

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
             * There is no separate tenant-registration page: an account is
             * created as part of signing up, in one form, because a user
             * without one cannot pass canAccessPanel() and would be shown
             * the door on the request that created them. Joining an existing
             * account stays an invitation its owner issues.
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

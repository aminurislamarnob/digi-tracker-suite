@php
    use Filament\Support\Enums\Width;

    $livewire ??= null;

    $renderHookScopes = $livewire?->getRenderHookScopes();
    $maxContentWidth ??= (filament()->getSimplePageMaxContentWidth() ?? Width::Large);

    if (is_string($maxContentWidth)) {
        $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
    }
@endphp

{{--
    The auth shell: sign in, sign up, and the two password-reset pages.

    Published from filament-panels so the page can be split in two -- the
    form on the left under a headline, a looping clip on the right -- rather
    than Filament's single centred card. Everything the upstream layout
    renders is still rendered, in the same order, so the render hooks and
    the signed-in header (edit-profile uses this layout too) keep working.
    The additions are the brand link, the hero, and the aside. Colours
    and type are the panel's own, in whichever theme the visitor chose;
    the layout lives in resources/css/filament/admin/auth.css.
--}}
<x-filament-panels::layout.base :livewire="$livewire">
    @props([
        'after' => null,
        'heading' => null,
        'subheading' => null,
    ])

    <div class="fi-simple-layout">
        @if (($hasTopbar ?? true) && filament()->auth()->check())
            <a href="#fi-main-content" class="fi-skip-link fi-sr-only">
                {{ __('filament-panels::layout.skip_to_content.label') }}
            </a>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_LAYOUT_START, scopes: $renderHookScopes) }}

        <a href="{{ filament()->getHomeUrl() ?? url('/') }}" class="fi-auth-brand">
            <x-filament-panels::logo />
        </a>

        @if (($hasTopbar ?? true) && filament()->auth()->check())
            <div class="fi-simple-layout-header">
                @if (filament()->hasDatabaseNotifications())
                    @livewire(filament()->getDatabaseNotificationsLivewireComponent(), [
                        'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                        'position' => \Filament\Enums\DatabaseNotificationsPosition::Topbar,
                    ])
                @endif

                @if (filament()->hasUserMenu())
                    @livewire(Filament\Livewire\SimpleUserMenu::class)
                @endif
            </div>
        @endif

        <div class="fi-simple-main-ctn">
            <div class="fi-auth-hero">
                <p class="fi-auth-hero-heading">Know your installs</p>
                <p class="fi-auth-hero-subheading">Telemetry and the public record, side by side.</p>
            </div>

            <main
                id="fi-main-content"
                tabindex="-1"
                @class([
                    'fi-simple-main',
                    ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                ])
            >
                {{ $slot }}
            </main>
        </div>

        {{--
            Decorative, so hidden from assistive tech. Muted and inline
            because autoplay is refused otherwise; the poster is what
            anybody sees before the first frame arrives, and all that
            anybody who prefers reduced motion sees at all.
        --}}
        <aside class="fi-auth-visual" aria-hidden="true">
            <video
                autoplay
                loop
                muted
                playsinline
                disablepictureinpicture
                preload="metadata"
                poster="{{ asset('media/auth-poster.jpg') }}"
                x-data
                x-init="window.matchMedia('(prefers-reduced-motion: reduce)').matches && ($el.removeAttribute('autoplay'), $el.pause())"
            >
                <source src="{{ asset('media/auth.mp4') }}" type="video/mp4" />
            </video>
        </aside>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_LAYOUT_END, scopes: $renderHookScopes) }}
    </div>
</x-filament-panels::layout.base>

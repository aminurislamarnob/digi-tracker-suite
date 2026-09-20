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
    The additions are the brand link, the hero, the aside, and the head
    script below. Type is the panel's; the colours are in
    resources/css/filament/admin/auth.css.

    Always dark, whatever theme the visitor has chosen for the panel.
    The class goes on <html> from the head, before anything paints, so
    Filament's dark variants apply to the whole document -- the toasts
    outside the wrapper included. These pages are full loads either
    side, so it never follows a visitor into the panel, where their own
    preference stands: that preference is read here, never written.
--}}
<x-filament-panels::layout.base :livewire="$livewire">
    @props([
        'after' => null,
        'heading' => null,
        'subheading' => null,
    ])

    @push('styles')
        <script>
            document.documentElement.classList.add('dark')

            /*
             * Filament keeps the theme in an Alpine store and mirrors it
             * onto <html>, so the store has to say dark as well or the
             * mirror takes the class straight back off. Filament's own
             * alpine:init listener is what fills the store from the
             * visitor's preference, and it is registered after this one,
             * so the override is queued to run once it has.
             */
            document.addEventListener('alpine:init', () =>
                queueMicrotask(() => window.Alpine.store('theme', 'dark')),
            )
        </script>
    @endpush

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

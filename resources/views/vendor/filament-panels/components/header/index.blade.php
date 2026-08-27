{{--
    Filament's page header, with the breadcrumbs moved to the right.

    Upstream puts them inside the heading block, stacked above the title on
    the left. There is no way to move them from CSS alone -- they are nested
    inside the flex child rather than being one -- and absolute positioning
    would drop them straight on top of the header actions on every page that
    has any. So the markup is overridden instead.

    Kept deliberately close to the original: same classes, same render
    hooks, same conditionals. The only structural change is that the
    breadcrumbs and the actions now share a right-hand column. If Filament's
    own header gains something, this file is where it has to be added --
    that is the standing cost of the override, and the reason nothing else
    here has been "improved" while passing through.

    Source: vendor/filament/filament/resources/views/components/header/index.blade.php
--}}

@props([
    'actions' => [],
    'actionsAlignment' => null,
    'breadcrumbs' => [],
    'heading' => null,
    'subheading' => null,
])

@php
    $beforeActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, scopes: $this->getRenderHookScopes());
    $afterActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER, scopes: $this->getRenderHookScopes());
    $hasActions = filled($beforeActions) || $actions || filled($afterActions);
@endphp

<header
    {{
        $attributes->class([
            'fi-header',
            /*
             * Not fi-header-has-breadcrumbs: that class exists to push the
             * actions down past breadcrumbs sitting above the heading, and
             * they no longer are. Keeping it would leave a gap where the
             * breadcrumbs used to be.
             */
        ])
    }}
>
    <div>
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, scopes: $this->getRenderHookScopes()) }}

        @if (filled($heading))
            <h1 class="fi-header-heading">
                {{ $heading }}
            </h1>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, scopes: $this->getRenderHookScopes()) }}

        @if (filled($subheading))
            <p class="fi-header-subheading">
                {{ $subheading }}
            </p>
        @endif
    </div>

    @if ($breadcrumbs || $hasActions)
        <div class="fi-header-end-ctn">
            @if ($breadcrumbs)
                <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
            @endif

            @if ($hasActions)
                <div class="fi-header-actions-ctn">
                    {{ $beforeActions }}

                    @if ($actions)
                        <x-filament::actions
                            :actions="$actions"
                            :alignment="$actionsAlignment"
                        />
                    @endif

                    {{ $afterActions }}
                </div>
            @endif
        </div>
    @endif
</header>

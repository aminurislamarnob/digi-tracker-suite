@php
    $figures = $this->getFigures();
@endphp

{{--
    A root element unconditionally, even with nothing to show: Livewire
    fails to render a component whose template resolves to no root tag, so
    an @if wrapping the whole view would take the page down for any project
    without a public listing.
--}}
<div>
    @if ($figures['linked'])
        {{--
            Same card shape as the public figures below the chart, on
            purpose: these are the same kind of number read over a shorter
            window, and giving them a second visual language would imply
            otherwise.

            All four come from wordpress.org's own summary endpoint, so this
            row and the plugin's advanced page show the same numbers.
        --}}
        <div class="grid grid-cols-2 gap-6 xl:grid-cols-4">
            <x-filament::section class="text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Today</p>
                <p class="mt-1 text-4xl font-bold tabular-nums text-gray-950 dark:text-white">
                    {{ $figures['today'] !== null ? number_format($figures['today']) : '—' }}
                </p>
                <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                    {{-- Not comparable to yesterday until the day closes. --}}
                    still being counted
                </p>
            </x-filament::section>

            <x-filament::section class="text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Yesterday</p>
                <p class="mt-1 text-4xl font-bold tabular-nums text-gray-950 dark:text-white">
                    {{ $figures['yesterday'] !== null ? number_format($figures['yesterday']) : '—' }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    the last complete day
                </p>
            </x-filament::section>

            <x-filament::section class="text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Last 7 days</p>
                <p class="mt-1 text-4xl font-bold tabular-nums text-gray-950 dark:text-white">
                    {{ $figures['lastWeek'] !== null ? number_format($figures['lastWeek']) : '—' }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{-- wordpress.org's window: seven complete days plus today. --}}
                    wordpress.org's own window
                </p>
            </x-filament::section>

            <x-filament::section class="text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">All time</p>
                <p class="mt-1 text-4xl font-bold tabular-nums text-gray-950 dark:text-white">
                    {{ $figures['allTime'] !== null ? number_format($figures['allTime']) : '—' }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    since the plugin was published
                </p>
            </x-filament::section>
        </div>

        @unless ($figures['live'])
            <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">
                {{--
                    Said out loud rather than shown as fresh. These four move
                    hourly, and a stale "today" that looks live is the whole
                    failure mode.
                --}}
                wordpress.org could not be reached, so these are from the last
                nightly capture rather than from just now.
            </p>
        @endunless
    @endif
</div>

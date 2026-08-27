@php
    $laggards = $this->getLaggards();
    $silent = $this->getSilentCount();
@endphp

<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Who is stuck behind --}}
        <x-filament::section>
            <x-slot name="heading">Sites behind the newest version</x-slot>
            <x-slot name="description">
                Newest is taken from what sites report, not from what was released — the latest
                version on wordpress.org is not necessarily the latest anybody is running.
            </x-slot>

            @if (! $laggards['newest'])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No rollup yet, so there is nothing to compare against.
                </p>
            @else
                <div class="flex items-baseline gap-3">
                    <span class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format($laggards['total']) }}
                    </span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        behind {{ $laggards['newest'] }}, which
                        {{ $laggards['newestShare'] }}% are running
                    </span>
                </div>

                @if ($laggards['behind']->isEmpty())
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        Everybody is on the newest version.
                    </p>
                @else
                    <dl class="mt-4 space-y-2">
                        @foreach ($laggards['behind'] as $version => $count)
                            <div class="flex items-center justify-between text-sm">
                                <dt class="font-medium text-gray-700 dark:text-gray-300">{{ $version }}</dt>
                                <dd class="tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ number_format($count) }}
                                    {{ Str::plural('site', $count) }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            @endif
        </x-filament::section>

        {{-- The honesty panel. --}}
        <x-filament::section>
            <x-slot name="heading">Sites that went quiet</x-slot>
            <x-slot name="description">
                Silent for over {{ \App\Models\Site::activeWindowDays() }} days without ever saying
                they were leaving.
            </x-slot>

            <div class="flex items-baseline gap-3">
                <span class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ number_format($silent) }}
                </span>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ Str::plural('site', $silent) }}
                </span>
            </div>

            {{--
                Worth stating rather than leaving to interpretation: this is
                usually not churn. The heartbeat rides on wp-cron, which is
                disabled or broken on a meaningful share of WordPress sites,
                so silence and departure look identical from here.
            --}}
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Mostly broken or disabled wp-cron rather than departures — the two are
                indistinguishable from this end. Treat it as the margin of error on the install
                count, not as churn.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>

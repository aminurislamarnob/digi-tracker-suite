@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$project->name" />

    @unless ($latest)
        <div
            class="mb-6 rounded-2xl border border-warning-200 bg-warning-50 px-5 py-4 dark:border-warning-500/30 dark:bg-warning-500/10">
            <h3 class="text-sm font-semibold text-warning-700 dark:text-warning-400">No rollup yet</h3>
            <p class="mt-1 text-sm text-warning-600 dark:text-warning-400/80">
                Charts read the nightly <code>daily_stats</code> table, which has no row for this project.
                Heartbeats may still be arriving. Run <code>telemetry:build-daily-stats</code> to see today.
            </p>
        </div>
    @endunless

    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
        @foreach ($headline as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <p class="text-xs tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $card['label'] }}</p>

                <div class="flex items-end justify-between mt-3">
                    <p class="text-3xl font-semibold text-gray-800 tabular-nums dark:text-white/90">
                        {{ is_float($card['value']) ? $card['value'] : number_format($card['value']) }}{{ $card['suffix'] ?? '' }}
                    </p>

                    @if ($card['delta'] !== null)
                        @php($good = ($card['inverted'] ?? false) ? $card['delta'] <= 0 : $card['delta'] >= 0)
                        <x-ui.badge :color="$good ? 'success' : 'error'" size="sm">
                            {{ $card['delta'] > 0 ? '+' : '' }}{{ $card['delta'] }}%
                        </x-ui.badge>
                    @endif
                </div>

                @if (array_filter($card['spark']))
                    <div class="mt-4 spark-chart" data-spark>
                        <script type="application/json">@json($card['spark'])</script>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Stated plainly, on the screen people quote from. Ingest has no
         authentication -- the protocol has none -- so anyone holding the
         hash, which is visible in GPL source, can post a heartbeat. --}}
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Counts are claimed, not proven: telemetry is unauthenticated by protocol design.
        "Tracked installs" counts sites that opted in, so it reads below the wordpress.org figure — that gap is
        the opt-in rate.
    </p>

    <div class="grid grid-cols-1 gap-5 mt-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-donut-card title="Site status" :counts="$statusCounts" />
        <x-donut-card title="Deactivation reasons" :counts="$reasonCounts" />

        {{-- Not $title: that is the page title the layout reads, and a loop
             variable of the same name silently renames the browser tab. --}}
        @foreach ($donuts as $donutTitle => $counts)
            <x-donut-card :title="$donutTitle" :counts="$counts" />
        @endforeach
    </div>
@endsection

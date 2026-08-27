@php
    $headline = $this->getHeadline();
    $versions = $this->getVersionComparison();
    $releases = $this->getReleases();
    $rankings = $this->getRankings();
@endphp

<x-filament-panels::page>
    @if (! $headline['linked'])
        <x-filament::section>
            <x-slot name="heading">Not linked to wordpress.org</x-slot>
            <x-slot name="description">
                Set the project's repository slug to collect public download counts, ratings,
                release dates and search rankings alongside your telemetry.
            </x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                A project without a public listing still collects telemetry perfectly well —
                it simply has no public half to compare against.
            </p>
        </x-filament::section>
    @else
        {{-- The headline trio. The middle number is the point of this page. --}}
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <x-filament::section>
                <x-slot name="heading">Public active installs</x-slot>
                <p class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ $headline['publicInstalls'] !== null ? number_format($headline['publicInstalls']) : '—' }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{-- Said plainly, because two of these subtracted is not growth. --}}
                    wordpress.org publishes this rounded to a bucket, never exactly.
                </p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Tracked installs</x-slot>
                <p class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ $headline['tracked'] !== null ? number_format($headline['tracked']) : '—' }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Sites that opted in and reported within 30 days. Claimed, not proven.
                </p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Opt-in rate</x-slot>
                @if ($headline['optInRate'] === null)
                    <p class="text-3xl font-semibold text-gray-400 dark:text-gray-500">—</p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{-- Never shown as 0%: that would claim nobody opted in. --}}
                        No public figure yet, so there is nothing to divide by.
                    </p>
                @else
                    <p class="text-3xl font-semibold tabular-nums text-primary-600 dark:text-primary-400">
                        {{ $headline['optInRate'] }}%
                    </p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Of the public figure. An estimate against a rounded number.
                    </p>
                @endif
            </x-filament::section>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Version split: theirs against ours --}}
            <x-filament::section>
                <x-slot name="heading">Version adoption, public vs tracked</x-slot>
                <x-slot name="description">
                    Two different measurements, shown together on purpose. A wide gap means the
                    sites that opted in are not representative of everybody running the plugin.
                </x-slot>

                @if (empty($versions))
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nothing captured yet. The first run of
                        <code class="text-xs">telemetry:fetch-repo-stats</code> fills this in.
                    </p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 text-left font-medium">Version line</th>
                                <th class="py-2 text-right font-medium">wordpress.org</th>
                                <th class="py-2 text-right font-medium">Ours</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($versions as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                    <td class="py-2 font-medium text-gray-700 dark:text-gray-300">
                                        {{ $row['version'] }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                        {{ $row['publicShare'] !== null ? $row['publicShare'] . '%' : '—' }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                        @if ($row['ourShare'] !== null)
                                            {{ $row['ourShare'] }}%
                                            <span class="text-xs text-gray-400">({{ number_format($row['ourCount']) }})</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Folded to minor lines, because wordpress.org reports “3.1” where telemetry
                        reports 3.1.4 and 3.1.7 separately.
                    </p>
                @endif
            </x-filament::section>

            {{-- Reputation --}}
            <x-filament::section>
                <x-slot name="heading">Reputation and support</x-slot>

                <dl class="space-y-4">
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Rating</dt>
                        <dd class="text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            @if ($headline['rating'] === null || ! $headline['numRatings'])
                                —
                            @else
                                {{ round($headline['rating'] / 20, 1) }} / 5
                                <span class="text-xs text-gray-400">
                                    from {{ number_format($headline['numRatings']) }}
                                    {{ Str::plural('rating', $headline['numRatings']) }}
                                </span>
                            @endif
                        </dd>
                    </div>

                    @if ($headline['numRatings'] !== null && $headline['numRatings'] > 0 && $headline['numRatings'] < 5)
                        <p class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            {{-- A percentage drawn from a handful of people is not a quality signal. --}}
                            Too few ratings to read as a score.
                        </p>
                    @endif

                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Support threads</dt>
                        <dd class="text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{ $headline['supportThreads'] !== null ? number_format($headline['supportThreads']) : '—' }}
                        </dd>
                    </div>

                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">Resolved</dt>
                        <dd class="text-sm tabular-nums text-gray-600 dark:text-gray-400">
                            {{-- Null, not 100%: no threads is no record either way. --}}
                            {{ $headline['resolutionRate'] !== null ? $headline['resolutionRate'] . '%' : 'no threads' }}
                        </dd>
                    </div>
                </dl>
            </x-filament::section>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Releases --}}
            <x-filament::section>
                <x-slot name="heading">Releases</x-slot>
                <x-slot name="description">
                    Dates read from the plugin's Subversion tags, so the history goes back further
                    than this application does.
                </x-slot>

                @if (empty($releases))
                    <p class="text-sm text-gray-500 dark:text-gray-400">No releases recorded yet.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 text-left font-medium">Version</th>
                                <th class="py-2 text-left font-medium">Released</th>
                                <th class="py-2 text-right font-medium">Days to 50%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($releases as $release)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                    <td class="py-2 font-medium text-gray-700 dark:text-gray-300">
                                        {{ $release['version'] }}
                                        @unless ($release['exact'])
                                            {{-- An observed date is an upper bound, and says so. --}}
                                            <span class="ml-1 text-xs text-amber-600 dark:text-amber-400"
                                                  title="Inferred from the version changing, so no later than this date">
                                                approx
                                            </span>
                                        @endunless
                                    </td>
                                    <td class="py-2 text-gray-600 dark:text-gray-400">
                                        {{ $release['releasedOn']->format('j M Y') }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                        {{-- Not there yet is not the same as slow, and never renders as 0. --}}
                                        {{ $release['daysToHalf'] !== null ? $release['daysToHalf'] : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Days to 50% measures adoption among sites that opted in, not among everybody.
                    </p>
                @endif
            </x-filament::section>

            {{-- Search rankings --}}
            <x-filament::section>
                <x-slot name="heading">Search rankings</x-slot>
                <x-slot name="description">
                    Position in wordpress.org search, and the move over the last week.
                </x-slot>

                @if (empty($rankings))
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No keywords tracked yet. Add them under the project's settings.
                    </p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 text-left font-medium">Keyword</th>
                                <th class="py-2 text-right font-medium">Position</th>
                                <th class="py-2 text-right font-medium">7-day move</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rankings as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                    <td class="py-2 font-medium text-gray-700 dark:text-gray-300">
                                        {{ $row['keyword'] }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        @if ($row['position'] === null)
                                            {{-- Outside the window is the absence of a rank, not a bad one. --}}
                                            <span class="text-xs text-gray-400">
                                                not in top {{ $row['depth'] ?? '—' }}
                                            </span>
                                        @else
                                            <span class="font-semibold text-gray-950 dark:text-white">
                                                #{{ $row['position'] }}
                                            </span>
                                            @if ($row['total'])
                                                <span class="text-xs text-gray-400">
                                                    of {{ number_format($row['total']) }}
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        @if ($row['movement'] === null)
                                            <span class="text-xs text-gray-400">—</span>
                                        @elseif ($row['movement'] > 0)
                                            <span class="text-success-600 dark:text-success-400">
                                                &uarr; {{ $row['movement'] }}
                                            </span>
                                        @elseif ($row['movement'] < 0)
                                            <span class="text-danger-600 dark:text-danger-400">
                                                &darr; {{ abs($row['movement']) }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">no change</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>

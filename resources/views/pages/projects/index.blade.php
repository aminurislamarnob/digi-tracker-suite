@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Projects" />

    @if ($projects->isEmpty())
        <div
            class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">No projects yet</h2>
            <p class="max-w-md mx-auto mt-2 text-sm text-gray-500 dark:text-gray-400">
                A project is one plugin. Create one to get its hash, then point the SDK at this server.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $row)
                @php($project = $row['project'])
                <a href="{{ route('projects.overview', $project) }}"
                    class="rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-700 md:p-6">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $project->name }}</h3>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $project->slug }}</p>
                        </div>

                        @unless ($project->is_active)
                            <x-ui.badge color="warning" size="sm">Paused</x-ui.badge>
                        @endunless
                    </div>

                    <div class="mt-6">
                        <p class="text-xs tracking-wide text-gray-500 uppercase dark:text-gray-400">Tracked installs</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-800 tabular-nums dark:text-white/90">
                            {{ number_format($row['latest']?->active_installs ?? 0) }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($row['latest'])
                                as of {{ $row['latest']->date->toFormattedDateString() }}
                            @else
                                no rollup yet
                            @endif
                        </p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection

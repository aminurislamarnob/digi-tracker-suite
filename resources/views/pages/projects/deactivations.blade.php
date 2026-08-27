@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Deactivations" />

    <form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
        <select name="reason"
            class="h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:text-white/90">
            <option value="">Any reason</option>
            @foreach ($reasons as $id => $label)
                <option value="{{ $id }}" @selected($reasonId === $id)>{{ $label }}</option>
            @endforeach
        </select>

        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
            <input type="checkbox" name="with_comment" value="1" @checked(request()->boolean('with_comment'))
                class="w-4 h-4 border-gray-300 rounded text-brand-500 dark:border-gray-700">
            Only those who wrote something
        </label>

        <button type="submit"
            class="h-11 rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">Filter</button>
    </form>

    <div class="space-y-3">
        @forelse ($deactivations as $deactivation)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <x-ui.badge color="error" size="sm">{{ $deactivation->reasonLabel() ?? 'Not given' }}</x-ui.badge>
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $deactivation->site?->canonical_url ?? 'unknown site' }}
                        </span>
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $deactivation->created_at->diffForHumans() }}
                        @if ($deactivation->reactivated_at)
                            · came back {{ $deactivation->reactivated_at->diffForHumans() }}
                        @endif
                    </span>
                </div>

                @if ($deactivation->reason_info)
                    <p class="mt-3 text-sm text-gray-800 dark:text-white/90">“{{ $deactivation->reason_info }}”</p>
                @endif

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    v{{ $deactivation->project_version ?? '?' }}
                    @if ($deactivation->theme_name)
                        · theme {{ $deactivation->theme_name }}
                    @endif
                </p>
            </div>
        @empty
            <div
                class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Nobody has deactivated. That is the good outcome.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-5">{{ $deactivations->links() }}</div>
@endsection

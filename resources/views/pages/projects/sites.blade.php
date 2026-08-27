@extends('layouts.app')

@php
    $input = 'h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:text-white/90';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Sites" />

    <form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by URL"
            class="{{ $input }} w-full sm:w-64">

        <select name="status" class="{{ $input }}">
            <option value="">Any status</option>
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'deactivated' => 'Deactivated'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="version" class="{{ $input }}">
            <option value="">Any version</option>
            @foreach ($versions as $version)
                <option value="{{ $version }}" @selected(($filters['version'] ?? '') === $version)>{{ $version }}</option>
            @endforeach
        </select>

        <select name="country" class="{{ $input }}">
            <option value="">Any country</option>
            @foreach ($countries as $country)
                <option value="{{ $country }}" @selected(($filters['country'] ?? '') === $country)>{{ $country }}</option>
            @endforeach
        </select>

        <button type="submit"
            class="h-11 rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">Filter</button>

        @if (array_filter($filters))
            <a href="{{ route('projects.sites', $project) }}"
                class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Clear</a>
        @endif
    </form>

    <div class="overflow-hidden border border-gray-200 rounded-2xl dark:border-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/[0.03]">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3 font-medium">Site</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Version</th>
                        <th class="px-5 py-3 font-medium">WordPress</th>
                        <th class="px-5 py-3 font-medium">PHP</th>
                        <th class="px-5 py-3 font-medium">Country</th>
                        <th class="px-5 py-3 font-medium">Last seen</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-800 dark:bg-transparent">
                    @forelse ($sites as $site)
                        <tr>
                            <td class="px-5 py-4">
                                <span class="text-gray-800 dark:text-white/90">{{ $site->canonical_url }}</span>
                                @if ($site->is_local)
                                    <x-ui.badge color="light" size="sm">local</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge size="sm"
                                    :color="match ($site->status) {
                                        'active' => 'success',
                                        'deactivated' => 'error',
                                        default => 'warning',
                                    }">
                                    {{ ucfirst($site->status) }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-gray-600 tabular-nums dark:text-gray-400">
                                {{ $site->current_version ?? '—' }}</td>
                            <td class="px-5 py-4 text-gray-600 tabular-nums dark:text-gray-400">
                                {{ $site->wp_version ?? '—' }}</td>
                            <td class="px-5 py-4 text-gray-600 tabular-nums dark:text-gray-400">
                                {{ $site->php_version ?? '—' }}</td>
                            <td class="px-5 py-4 text-gray-600 dark:text-gray-400">{{ $site->country ?? '—' }}</td>
                            <td class="px-5 py-4 text-gray-600 dark:text-gray-400">
                                {{ $site->last_seen_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">
                                No sites match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">{{ $sites->links() }}</div>
@endsection

@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="End users" />

    <form method="GET" class="flex flex-wrap items-center gap-3 mb-2">
        <input type="email" name="email" value="{{ $email }}" placeholder="Exact email address"
            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:text-white/90 sm:w-80">

        <button type="submit"
            class="h-11 rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">Search</button>

        @if ($email !== '')
            <a href="{{ route('projects.end-users', $project) }}"
                class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Clear</a>
        @endif
    </form>

    {{-- Not a missing feature. Addresses are encrypted at rest and reachable
         only through a keyed blind index, so exact match is the only lookup
         the storage design permits -- which is the difference between
         answering a support ticket and browsing a mailing list. --}}
    <p class="mb-5 text-xs text-gray-500 dark:text-gray-400">
        Email is encrypted at rest; search matches whole addresses only.
    </p>

    <div class="overflow-hidden border border-gray-200 rounded-2xl dark:border-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/[0.03]">
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Email</th>
                        <th class="px-5 py-3 font-medium">Sites</th>
                        <th class="px-5 py-3 font-medium">Marketing consent</th>
                        <th class="px-5 py-3 font-medium">Last seen</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-800 dark:bg-transparent">
                    @forelse ($endUsers as $endUser)
                        <tr>
                            <td class="px-5 py-4 text-gray-800 dark:text-white/90">
                                {{ trim($endUser->first_name.' '.$endUser->last_name) ?: '—' }}
                            </td>
                            <td class="px-5 py-4 text-gray-600 dark:text-gray-400">{{ $endUser->email }}</td>
                            <td class="px-5 py-4 text-gray-600 tabular-nums dark:text-gray-400">
                                {{ $endUser->sites_count }}</td>
                            <td class="px-5 py-4">
                                @if ($endUser->hasMarketingConsent())
                                    <x-ui.badge color="success" size="sm">given</x-ui.badge>
                                @else
                                    {{-- Telemetry opt-in is not marketing opt-in. --}}
                                    <x-ui.badge color="light" size="sm">not given</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-gray-600 dark:text-gray-400">
                                {{ $endUser->last_seen_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">
                                {{ $email !== '' ? 'No end user with that address.' : 'No end users yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">{{ $endUsers->links() }}</div>
@endsection

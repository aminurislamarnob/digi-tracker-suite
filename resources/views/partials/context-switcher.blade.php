@php
    $account = \App\Support\CurrentAccount::get();
    $project = \App\Support\CurrentProject::get();
    $accounts = auth()->user()?->accounts()->orderBy('accounts.name')->get() ?? collect();
    $projects = $account ? \App\Models\Project::orderBy('name')->get() : collect();
@endphp

<div class="items-center hidden gap-2 xl:flex">
    {{-- Only shown when there is a choice to make. One agency with one
         account should not be looking at a switcher that never moves. --}}
    @if ($accounts->count() > 1)
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button @click="open = !open" type="button"
                class="inline-flex items-center gap-2 px-4 text-sm font-medium text-gray-700 border border-gray-200 rounded-lg h-11 dark:border-gray-800 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                <span>{{ $account?->name ?? 'No account' }}</span>
                <svg class="stroke-current" width="16" height="16" viewBox="0 0 20 20" fill="none">
                    <path d="M4.79 7.4L10 12.6l5.21-5.2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <div x-show="open" x-cloak
                class="absolute left-0 mt-2 w-64 rounded-2xl border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                @foreach ($accounts as $option)
                    <form method="POST" action="{{ route('accounts.switch', $option) }}">
                        @csrf
                        <button type="submit"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                            <span>{{ $option->name }}</span>
                            @if ($option->is($account))
                                <span class="text-xs text-brand-500">current</span>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    @if ($projects->isNotEmpty())
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button @click="open = !open" type="button"
                class="inline-flex items-center gap-2 px-4 text-sm font-medium text-gray-700 border border-gray-200 rounded-lg h-11 dark:border-gray-800 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                <span>{{ $project?->name ?? 'Choose a project' }}</span>
                <svg class="stroke-current" width="16" height="16" viewBox="0 0 20 20" fill="none">
                    <path d="M4.79 7.4L10 12.6l5.21-5.2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <div x-show="open" x-cloak
                class="absolute left-0 mt-2 w-64 rounded-2xl border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                @foreach ($projects as $option)
                    <a href="{{ route('projects.overview', $option) }}"
                        class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        <span>{{ $option->name }}</span>
                        @if ($project && $option->is($project))
                            <span class="text-xs text-brand-500">current</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>

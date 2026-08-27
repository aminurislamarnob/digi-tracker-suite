<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button @click="open = !open" type="button"
        class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
        <span
            class="flex items-center justify-center w-10 h-10 text-sm font-semibold rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
            {{ \Illuminate\Support\Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
        </span>
        <span class="hidden sm:block">{{ auth()->user()->name }}</span>
    </button>

    <div x-show="open" x-cloak
        class="absolute right-0 mt-3 w-64 rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
        <div class="px-2 pb-3 border-b border-gray-200 dark:border-gray-800">
            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ auth()->user()->name }}</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</p>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="pt-2">
            @csrf
            <button type="submit"
                class="w-full rounded-lg px-2 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                Sign out
            </button>
        </form>
    </div>
</div>

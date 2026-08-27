<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · Digi Tracker Suite</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            if ((savedTheme || systemTheme) === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="w-full max-w-md">
            <div class="flex justify-center mb-8">
                <img class="dark:hidden" src="/images/logo/logo.svg" alt="Digi Tracker Suite" width="170">
                <img class="hidden dark:block" src="/images/logo/logo-dark.svg" alt="Digi Tracker Suite" width="170">
            </div>

            <div
                class="p-6 bg-white border border-gray-200 rounded-2xl shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-8">
                <h1 class="mb-1 text-2xl font-semibold text-gray-800 dark:text-white/90">Sign in</h1>
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    Telemetry for your WordPress plugins.
                </p>

                @if ($errors->any())
                    <div
                        class="mb-5 rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email"
                            class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                            autocomplete="username"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">
                    </div>

                    <div>
                        <label for="password"
                            class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                        <input type="checkbox" name="remember" value="1"
                            class="w-4 h-4 border-gray-300 rounded text-brand-500 focus:ring-brand-500/20 dark:border-gray-700">
                        Keep me signed in
                    </label>

                    <button type="submit"
                        class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white transition hover:bg-brand-600">
                        Sign in
                    </button>
                </form>
            </div>

            {{-- No sign-up link: accounts are created by hand until the
                 success test decides whether this becomes a product. --}}
            <p class="mt-6 text-xs text-center text-gray-500 dark:text-gray-400">
                Access is by invitation.
            </p>
        </div>
    </div>
</body>

</html>

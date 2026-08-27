<?php

use App\Http\Middleware\SetCurrentAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Telemetry ingest. Deliberately not `apiPrefix: 'api'` — the SDK posts to
        // bare paths (/track, /deactivate, /tracking-skipped) and those URLs are
        // baked into installed plugins forever.
        api: __DIR__.'/../routes/ingest.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account' => SetCurrentAccount::class,
        ]);

        /*
         * Behind Cloudflare the TCP peer is an edge, not the site that sent
         * the heartbeat. Recovering the real address is what keeps the
         * per-IP throttle meaningful and the country column honest; see
         * config/proxies.php for why the list is narrow rather than '*'.
         */
        // Required rather than read through config(): this closure runs
        // while the application is still being built, before the config
        // repository is bound.
        $middleware->trustProxies(
            at: (require __DIR__.'/../config/proxies.php')['proxies'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Ingest lives at bare paths, not under /api, because those URLs are
         * baked into installed plugins. Without listing them here a failed
         * validation would answer a telemetry POST with a 302 to the login
         * page, which is meaningless to the SDK and misleading in the logs.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('track', 'deactivate', 'tracking-skipped')
                || $request->is('public/license/*', 'v2/update/*')
                || $request->expectsJson(),
        );
    })->create();

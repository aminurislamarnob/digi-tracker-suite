<?php

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
        //
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
                || $request->expectsJson(),
        );
    })->create();

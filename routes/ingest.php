<?php

use App\Http\Controllers\Ingest\DeactivateController;
use App\Http\Controllers\Ingest\TrackController;
use App\Http\Controllers\Ingest\TrackingSkippedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telemetry ingest
|--------------------------------------------------------------------------
|
| These paths are baked into every installed copy of our plugins and can
| never change. They are wire-compatible with the Appsero SDK, so a site
| running the unmodified appsero/client can post here too.
|
| There is no authentication: the protocol has none. The hash is a plain
| body field visible in GPL source, so treat every field as claimed, not
| proven. Throttling and anomaly detection are the only controls we have.
|
*/

Route::middleware('throttle:ingest')->group(function () {
    Route::post('/track', TrackController::class)->name('ingest.track');
    Route::post('/deactivate', DeactivateController::class)->name('ingest.deactivate');
    Route::post('/tracking-skipped', TrackingSkippedController::class)->name('ingest.tracking-skipped');
});

/*
 * Licensing and updates are out of scope -- all four plugins are free and
 * hosted on wordpress.org. The namespaces are reserved here anyway: an SDK
 * configured with licensing enabled would otherwise get our HTML 404 page,
 * and 501 says "not implemented", which is both true and diagnosable.
 */
Route::any('/public/license/{hash}/{action}', fn () => response()->json([
    'success' => false,
    'error' => 'Licensing is not implemented on this endpoint.',
], 501))->whereIn('action', ['check', 'activate', 'deactivate']);

Route::any('/v2/update/{hash}/check', fn () => response()->json([
    'success' => false,
    'error' => 'Updates are served by wordpress.org, not this endpoint.',
], 501));

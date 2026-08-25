<?php

use App\Http\Controllers\Ingest\TrackController;
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

Route::post('/track', TrackController::class)
    ->middleware('throttle:ingest')
    ->name('ingest.track');

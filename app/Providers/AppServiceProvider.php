<?php

namespace App\Providers;

use App\Contracts\GeoLocator;
use App\Services\Geo\MaxMindGeoLocator;
use App\Services\Geo\NullGeoLocator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeoLocator::class, function () {
            $database = config('telemetry.geoip.database');

            return $database
                ? new MaxMindGeoLocator($database)
                : new NullGeoLocator;
        });
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * The ingest protocol has no authentication, so throttling is the only
     * control we have on forged traffic. The limits are generous against
     * the real shape of the workload -- one heartbeat per site per week --
     * so a legitimate site never comes close, while a flood from a single
     * source or a single hash stops quickly.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ingest', fn (Request $request) => [
            Limit::perHour(60)->by($request->ip()),
            Limit::perHour(600)->by('hash:'.$request->input('hash')),
        ]);
    }
}

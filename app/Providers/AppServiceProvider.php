<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

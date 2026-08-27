<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site status windows
    |--------------------------------------------------------------------------
    |
    | The heartbeat is weekly and client-enforced, so silence is ambiguous:
    | a site with a broken wp-cron looks exactly like one that vanished.
    | Thirty days is four missed beats -- slow enough not to invent churn,
    | fast enough to be worth reading.
    |
    */

    'active_window_days' => (int) env('TELEMETRY_ACTIVE_WINDOW_DAYS', 30),

    'inactive_window_days' => (int) env('TELEMETRY_INACTIVE_WINDOW_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Duplicate suppression
    |--------------------------------------------------------------------------
    |
    | A legitimate site reports once a week. Anything repeating within this
    | window is a retry, a misconfigured cron, or noise -- record the site
    | as seen, but do not let it inflate the report history.
    |
    */

    'duplicate_window_hours' => (int) env('TELEMETRY_DUPLICATE_WINDOW_HOURS', 6),

    /*
    |--------------------------------------------------------------------------
    | Anomaly detection
    |--------------------------------------------------------------------------
    |
    | There is no authentication on ingest, by protocol design. These are
    | the thresholds at which traffic stops looking like WordPress sites
    | phoning home once a week and starts looking like something else.
    |
    */

    'anomaly' => [
        'new_sites_per_hour' => (int) env('TELEMETRY_ANOMALY_NEW_SITES', 200),
        'payloads_per_hour' => (int) env('TELEMETRY_ANOMALY_PAYLOADS', 2000),
        'payloads_per_ip_per_hour' => (int) env('TELEMETRY_ANOMALY_PER_IP', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoIP
    |--------------------------------------------------------------------------
    |
    | Self-hosted GeoLite2-Country. Absent a database file the platform
    | records no country rather than calling out to a lookup API on every
    | heartbeat -- see App\Contracts\GeoLocator.
    |
    */

    'geoip' => [
        'database' => env('GEOIP_DATABASE'),
    ],

    'auth' => [

        /*
         * Whether anybody may create an account.
         *
         * Registration creates a tenant, not just a login, so an open form
         * on a public host means a stranger can create an organisation
         * inside the platform. That is correct for a product and wrong for
         * an internal tool, and this application is currently the second
         * one -- the plan's own decision was that accounts are created by
         * hand until the success test says otherwise.
         *
         * Left on so the feature is usable; close it with one line in .env
         * the moment the sign-up link is not wanted in public.
         */
        'registration' => filter_var(env('TELEMETRY_REGISTRATION', true), FILTER_VALIDATE_BOOL),

    ],

];

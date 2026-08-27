<?php

/**
 * Plugin Name: Digi Tracker — local testing overrides
 * Description: Points telemetry at a local Digi Tracker Suite and makes a .test site report as if it were production. Drop into wp-content/mu-plugins/ while testing. NEVER ship this.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 */

// Do not call the file directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Send telemetry to the local server instead of production.
 *
 * `php artisan serve` binds 127.0.0.1, which is reachable because the SDK
 * posts server-side with wp_remote_post -- this never travels through the
 * browser, so a loopback address is fine.
 */
add_filter(
    'digi_tracker_endpoint',
    function () {
        return 'http://127.0.0.1:8123';
    }
);

/**
 * Report as a production site.
 *
 * Insights::is_local_server() treats .test, .local, .localhost and loopback
 * addresses as local and sets is_local=1. The server then excludes the site
 * from install counts -- correct in production, and the reason a local test
 * otherwise shows a working pipeline and a dashboard reading zero.
 */
add_filter('digi_tracker_is_local', '__return_false');

/**
 * WordPress refuses loopback HTTP requests to private addresses unless the
 * host is explicitly allowed. Without this every heartbeat fails silently
 * with "A valid URL was not provided".
 */
add_filter(
    'http_request_host_is_external',
    function ($external, $host) {
        return in_array($host, ['127.0.0.1', 'localhost'], true) ? true : $external;
    },
    10,
    2
);

/**
 * The weekly schedule makes testing tedious: opt in, then wait seven days.
 * This adds an admin-bar link that fires a heartbeat immediately.
 *
 * It calls the scheduled hook rather than send_tracking_data( true ), so
 * consent is still honoured -- opt out and this sends nothing, exactly as
 * in production.
 */
add_action(
    'admin_bar_menu',
    function ($bar) {
        if (! current_user_can('manage_options')) {
            return;
        }

        $bar->add_node(
            [
                'id' => 'digi-tracker-ping',
                'title' => 'Send telemetry now',
                'href' => wp_nonce_url(admin_url('?digi_tracker_ping=1'), 'digi_tracker_ping'),
            ]
        );
    },
    100
);

add_action(
    'admin_init',
    function () {
        if (empty($_GET['digi_tracker_ping']) || ! current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('digi_tracker_ping');

        // Whatever slug the plugin under test registered.
        do_action('metadata-viewer_tracker_send_event');

        wp_safe_redirect(admin_url('?digi_tracker_pinged=1'));
        exit;
    }
);

add_action(
    'admin_notices',
    function () {
        if (! empty($_GET['digi_tracker_pinged'])) {
            echo '<div class="notice notice-success"><p>Telemetry heartbeat fired. Check the queue on the server.</p></div>';
        }
    }
);

<?php
/**
 * Plugin Name: Digi Tracker — local harness
 * Description: Wires the Digi Tracker SDK into Metadata Viewer and points it at a local server, without editing the plugin. Drop into wp-content/mu-plugins/. NEVER ship this.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 *
 * ---------------------------------------------------------------------------
 * Why an mu-plugin rather than editing metadata-viewer.php
 * ---------------------------------------------------------------------------
 *
 * For a local smoke test this is strictly better: the plugin's source stays
 * pristine, so nothing has to be reverted afterwards and there is no risk of
 * a local-only endpoint override reaching a release. Everything below is
 * removed by deleting one file.
 *
 * It exercises the same code the shipped integration will -- same SDK, same
 * consent flow, same payload. The one thing it does NOT test is the wiring
 * itself: the release still needs the constructor call inside
 * metadata-viewer.php, because an mu-plugin will not exist on a user's site.
 */

// Do not call the file directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The project hash from the dashboard. Not a secret -- it travels as a plain
 * body field and is visible in the GPL source of every released plugin -- but
 * it is the routing key, so a typo silently sends telemetry nowhere.
 */
const DIGI_TRACKER_LOCAL_HASH = 'ef4080cb-095b-4f4f-91ec-fc95fd69018d';

/**
 * Where `php artisan serve` is listening.
 *
 * A loopback address is fine: the SDK posts server-side with wp_remote_post,
 * so this request never travels through the browser.
 */
const DIGI_TRACKER_LOCAL_ENDPOINT = 'http://127.0.0.1:8123';

add_filter( 'digi_tracker_endpoint', function () {
    return DIGI_TRACKER_LOCAL_ENDPOINT;
} );

/**
 * Report as a production site.
 *
 * Insights::is_local_server() treats .test, .local, .localhost and loopback
 * addresses as local and sets is_local=1. The server then excludes the site
 * from install counts -- correct in production, and the reason a local test
 * otherwise shows a working pipeline and a dashboard reading zero.
 */
add_filter( 'digi_tracker_is_local', '__return_false' );

/**
 * WordPress refuses loopback HTTP requests to private addresses unless the
 * host is explicitly allowed. Without this every heartbeat fails silently.
 */
add_filter( 'http_request_host_is_external', function ( $external, $host ) {
    return in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ? true : $external;
}, 10, 2 );

/**
 * Boot the SDK against Metadata Viewer.
 *
 * On plugins_loaded so the plugin is present and its version readable. The
 * SDK derives slug and version from the file path it is given, so that path
 * has to be the plugin's real main file, not this one.
 */
add_action( 'plugins_loaded', function () {
    $plugin_file = WP_PLUGIN_DIR . '/metadata-viewer/metadata-viewer.php';

    if ( ! file_exists( $plugin_file ) ) {
        return;
    }

    if ( ! class_exists( '\PluginizeLab\DigiTracker\Client' ) ) {
        $lib = WP_PLUGIN_DIR . '/metadata-viewer/lib/digi-tracker';

        if ( ! file_exists( $lib . '/Client.php' ) ) {
            return;
        }

        require_once $lib . '/Client.php';
        require_once $lib . '/Insights.php';
    }

    $client = new \PluginizeLab\DigiTracker\Client(
        DIGI_TRACKER_LOCAL_HASH,
        'Metadata Viewer',
        $plugin_file
    );

    $insights = $client->insights();

    // Names of the other active plugins. Disclosed in the opt-in notice.
    $insights->add_plugin_data();

    $insights->init();
}, 20 );

/**
 * The schedule is weekly, which makes testing tedious: opt in, then wait
 * seven days. This fires the scheduled hook on demand from the admin bar.
 *
 * It triggers the same hook wp-cron would, rather than calling
 * send_tracking_data( true ), so consent is still honoured -- opt out and
 * this sends nothing, exactly as in production.
 */
add_action( 'admin_bar_menu', function ( $bar ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $bar->add_node( array(
        'id'    => 'digi-tracker-ping',
        'title' => 'Send telemetry now',
        'href'  => wp_nonce_url( admin_url( '?digi_tracker_ping=1' ), 'digi_tracker_ping' ),
    ) );
}, 100 );

add_action( 'admin_init', function () {
    if ( empty( $_GET['digi_tracker_ping'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'digi_tracker_ping' );

    do_action( 'metadata-viewer_tracker_send_event' );

    wp_safe_redirect( admin_url( '?digi_tracker_pinged=1' ) );
    exit;
} );

add_action( 'admin_notices', function () {
    if ( empty( $_GET['digi_tracker_pinged'] ) ) {
        return;
    }

    $allowed = get_option( 'metadata-viewer_allow_tracking', 'no' );

    if ( 'yes' === $allowed ) {
        echo '<div class="notice notice-success"><p>Heartbeat fired. Run the queue worker on the server to see it land.</p></div>';
    } else {
        // Not a failure. This is consent working.
        echo '<div class="notice notice-warning"><p>Nothing sent: tracking has not been allowed for this plugin.</p></div>';
    }
} );

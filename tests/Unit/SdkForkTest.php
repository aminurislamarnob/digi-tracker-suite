<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the SDK fork against upstream leaking back in.
 *
 * The fork will be re-synced with `appsero/client` whenever upstream fixes
 * something worth having, and a re-sync is a copy-and-rename. These assert
 * the renames that carry consequences: a missed namespace sends our
 * telemetry to a competitor's servers on any site running Dokan, and a
 * missed consent string discloses the wrong company to the person being
 * asked for permission.
 *
 * Static checks on purpose. Actually booting the SDK needs WordPress, and
 * stubbing enough of it to instantiate Client would test the stubs.
 */
class SdkForkTest extends TestCase
{
    /** Resolved from disk, not base_path(): this is a unit test and boots no app. */
    protected static function path(string $file): string
    {
        return dirname(__DIR__, 2)."/packages/digi-tracker-client/src/{$file}";
    }

    protected static function sdk(string $file): string
    {
        return file_get_contents(static::path($file));
    }

    public static function sourceFiles(): array
    {
        return [
            'Client' => ['Client.php'],
            'Insights' => ['Insights.php'],
        ];
    }

    /**
     * The collision this fork exists to prevent: dokan-lite bundles
     * appsero/client unscoped, so on a Dokan site whichever loads first
     * wins -- and if that is Dokan's copy, our telemetry silently goes to
     * Appsero.
     */
    #[DataProvider('sourceFiles')]
    public function test_no_trace_of_upstream_remains(string $file): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            'appsero',
            static::sdk($file),
            "{$file} still references upstream",
        );
    }

    #[DataProvider('sourceFiles')]
    public function test_it_uses_our_namespace(string $file): void
    {
        $this->assertStringContainsString('namespace PluginizeLab\DigiTracker;', static::sdk($file));
    }

    /**
     * An undisclosed outbound request from the customer's server to a third
     * party named in no privacy policy, on every heartbeat, answering a
     * question our endpoint can answer for free.
     */
    public function test_the_third_party_ip_lookup_is_gone(): void
    {
        $insights = static::sdk('Insights.php');

        // Asserted on behaviour, not on the string: the word survives in the
        // comment explaining why the call was removed, and a test that
        // forbids mentioning it would forbid explaining it.
        $this->assertStringNotContainsString(
            'wp_remote_get',
            $insights,
            'Insights should make no outbound request of its own',
        );

        preg_match(
            '/function get_user_ip_address\(\)\s*\{(.*?)
    \}/s',
            $insights,
            $matches,
        );

        $this->assertNotEmpty($matches, 'get_user_ip_address should still exist');
        $this->assertStringContainsString("return '';", $matches[1]);
    }

    /**
     * Hooks are renamed for the same reason as the namespace: a shared
     * `appsero_endpoint` filter means one plugin redirecting its own
     * telemetry redirects everyone's on that site.
     */
    public function test_every_hook_is_namespaced_to_us(): void
    {
        $source = static::sdk('Client.php').static::sdk('Insights.php');

        preg_match_all("/apply_filters\(\s*'([a-z0-9_]+)'/", $source, $matches);

        $this->assertNotEmpty($matches[1], 'the SDK should expose filters');

        foreach ($matches[1] as $hook) {
            $this->assertStringStartsWith('digi_tracker_', $hook, "hook [{$hook}] is not namespaced");
        }
    }

    /**
     * The disclosure is what makes this legal under wordpress.org
     * Guideline 7. Naming the wrong service would make it worthless.
     */
    public function test_the_consent_notice_names_us(): void
    {
        $insights = static::sdk('Insights.php');

        $this->assertStringContainsString('operated by PluginizeLab', $insights);
        $this->assertStringContainsString('digi_tracker_privacy_policy_url', $insights);
    }

    /**
     * A server that accepts both this fork and unmodified upstream needs to
     * tell them apart, and the two have unrelated version histories.
     */
    public function test_the_client_version_is_distinguishable(): void
    {
        $this->assertMatchesRegularExpression(
            "/public \\\$version = 'dt-[0-9.]+';/",
            static::sdk('Client.php'),
        );
    }

    /**
     * Licensing and updates are out of scope -- the plugins are free and
     * wordpress.org serves their updates. Accessors requiring classes we do
     * not ship would fatal for anyone who called them.
     */
    public function test_licensing_and_updater_accessors_are_removed(): void
    {
        $client = static::sdk('Client.php');

        $this->assertStringNotContainsString('public function license()', $client);
        $this->assertStringNotContainsString('public function updater()', $client);
    }

    /**
     * The whole approach depends on the body staying byte-identical, so the
     * server can accept traffic from either client.
     */
    public function test_the_wire_format_is_unchanged(): void
    {
        $insights = static::sdk('Insights.php');

        foreach ([
            "'url'", "'site'", "'admin_email'", "'first_name'", "'last_name'",
            "'project_version'", "'server'", "'wp'", "'ip_address'",
            "'is_local'", "'tracking_skipped'", "'reason_id'", "'reason_info'",
            "'previously_skipped'",
        ] as $field) {
            $this->assertStringContainsString($field, $insights, "payload lost {$field}");
        }

        // Routes are compiled into every released plugin and can never change.
        foreach (["'track'", "'deactivate'", "'tracking-skipped'"] as $route) {
            $this->assertStringContainsString($route, $insights, "route {$route} went missing");
        }
    }

    /**
     * The reason this matters more than any other test here.
     *
     * Upstream guards the heartbeat with a consent check and then omits one
     * on the deactivation route, which sends the *entire* insights payload
     * -- URL, admin email, name, environment, plugin list. Somebody who
     * clicked "No thanks" discloses all of it by filling in a dialog that
     * asks a single question.
     */
    public function test_every_outbound_request_is_behind_a_consent_check(): void
    {
        $insights = static::sdk('Insights.php');

        // Split on each call site and look backwards for a guard.
        $parts = preg_split('/send_request\(/', $insights);
        array_pop($parts);

        $this->assertGreaterThan(0, count($parts), 'the SDK should send something');

        foreach ($parts as $i => $before) {
            // The refusal signal is the one exception, and is allowed to
            // fire precisely because it identifies nobody: a project hash
            // and a boolean. Without it the opt-in rate is unmeasurable.
            if (str_contains(substr($insights, strlen(implode('send_request(', array_slice($parts, 0, $i + 1))), 120), 'tracking-skipped')) {
                continue;
            }

            $window = substr($before, -1600);

            $this->assertStringContainsString(
                'tracking_allowed()',
                $window,
                "send_request() call #{$i} has no consent check within reach",
            );
        }
    }

    /**
     * Asking somebody why they are leaving, then discarding the answer
     * because they never consented, is worse than not asking.
     */
    public function test_the_deactivation_dialog_is_gated_on_consent(): void
    {
        $insights = static::sdk('Insights.php');

        preg_match('/function deactivate_scripts\(\).*?
    \}/s', $insights, $m);

        $this->assertNotEmpty($m, 'deactivate_scripts should exist');
        $this->assertStringContainsString('tracking_allowed()', $m[0]);
    }

    #[DataProvider('sourceFiles')]
    public function test_it_parses(string $file): void
    {
        exec('php -l '.escapeshellarg(static::path($file)), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }
}

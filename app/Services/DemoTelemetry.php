<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMetaField;
use App\Models\RawPayload;
use App\Models\Site;
use App\Models\TrackingSkip;
use App\Support\SiteUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invents a plausible telemetry history for a demo project.
 *
 * Deliberately routed through SiteReconciler rather than inserted straight
 * into the tables. Bulk inserts would be far quicker, but they would test
 * nothing: the whole point of this data is to exercise the parsing, the
 * metadata whitelist, the plugin sync, the duplicate suppression and the
 * status transitions with the same code a real heartbeat travels through.
 * If the reconciler has a bug, this generator should hit it.
 *
 * Payloads are built in the wire format -- form-encoded values, so every
 * boolean is "1" or an empty string and every number is a string. Building
 * clean PHP types here would quietly test the wrong thing.
 */
class DemoTelemetry
{
    /**
     * Environment mixes, roughly matching what WordPress actually reports
     * in the wild. Weighted so the charts show a believable long tail
     * rather than an even split nobody would ever see.
     */
    protected const PHP_VERSIONS = [
        '8.2.18' => 24, '8.3.14' => 22, '8.1.29' => 14, '8.4.3' => 11,
        '7.4.33' => 12, '8.0.30' => 7, '8.2.4' => 6, '7.3.33' => 4,
    ];

    protected const WP_VERSIONS = [
        '6.8.1' => 34, '6.7.2' => 27, '6.6.2' => 12, '6.5.5' => 8,
        '6.4.5' => 7, '6.3.5' => 5, '5.9.9' => 4, '6.2.6' => 3,
    ];

    protected const MYSQL_VERSIONS = [
        '8.0.36' => 38, '10.6.18-MariaDB' => 24, '5.7.44' => 16,
        '10.11.9-MariaDB' => 13, '8.4.3' => 9,
    ];

    protected const SERVERS = [
        'Apache/2.4.58' => 34, 'nginx/1.24.0' => 27, 'LiteSpeed' => 21,
        'nginx/1.22.1' => 10, 'Microsoft-IIS/10.0' => 8,
    ];

    protected const LOCALES = [
        'en_US' => 44, 'en_GB' => 9, 'de_DE' => 8, 'es_ES' => 7, 'fr_FR' => 7,
        'pt_BR' => 6, 'ru_RU' => 5, 'it_IT' => 5, 'nl_NL' => 4, 'ja' => 3, 'tr_TR' => 2,
    ];

    protected const COUNTRIES = [
        'US' => 30, 'DE' => 10, 'GB' => 9, 'FR' => 7, 'IN' => 7, 'BR' => 6,
        'CA' => 5, 'NL' => 5, 'AU' => 4, 'ES' => 4, 'IT' => 4, 'JP' => 3, 'PL' => 3, 'SE' => 3,
    ];

    protected const THEMES = [
        'astra' => ['Astra', '4.8.2'],
        'generatepress' => ['GeneratePress', '3.5.1'],
        'twentytwentyfive' => ['Twenty Twenty-Five', '1.2'],
        'twentytwentyfour' => ['Twenty Twenty-Four', '1.3'],
        'kadence' => ['Kadence', '1.2.9'],
        'hello-elementor' => ['Hello Elementor', '3.2.1'],
        'oceanwp' => ['OceanWP', '3.6.0'],
        'divi' => ['Divi', '4.27.4'],
    ];

    protected const NEIGHBOUR_PLUGINS = [
        'woocommerce' => ['WooCommerce', '9.4.2'],
        'elementor' => ['Elementor', '3.25.10'],
        'contact-form-7' => ['Contact Form 7', '6.0.3'],
        'wordpress-seo' => ['Yoast SEO', '23.9'],
        'akismet' => ['Akismet Anti-spam', '5.3.5'],
        'wordfence' => ['Wordfence Security', '8.0.1'],
        'wpforms-lite' => ['WPForms Lite', '1.9.2.2'],
        'updraftplus' => ['UpdraftPlus', '1.24.12'],
        'litespeed-cache' => ['LiteSpeed Cache', '6.5.2'],
        'jetpack' => ['Jetpack', '14.1'],
        'classic-editor' => ['Classic Editor', '1.6.5'],
        'duplicate-post' => ['Yoast Duplicate Post', '4.5'],
    ];

    /**
     * What people actually write, per reason. Generic lorem would make the
     * deactivations screen look populated while testing nothing about how
     * real feedback reads at a glance.
     */
    protected const COMMENTS = [
        'could-not-understand' => [
            'The settings screen has no explanation of what the options do.',
            'I could not work out where the output was supposed to appear.',
        ],
        'found-better-plugin' => [
            'Switched to a plugin that has a block editor panel.',
            'Found something that does the same job with WP-CLI support.',
            'Moved to a paid plugin that includes support.',
        ],
        'not-have-that-feature' => [
            'Needed multisite support.',
            'No way to export the data as CSV.',
            'I need this to work with custom post types.',
            'Wanted a REST endpoint so I could automate it.',
        ],
        'is-not-working' => [
            'Fatal error on activation with PHP 8.3.',
            'Blank screen in the admin after enabling it.',
            'Conflicts with WooCommerce checkout.',
        ],
        'looking-for-other' => [
            'Misread the description, not what I needed.',
        ],
        'did-not-work-as-expected' => [
            'I expected it to apply to existing posts, not only new ones.',
            'Thought it would include the front end too.',
        ],
        'other' => [
            'Just tidying up plugins I no longer use.',
            'Site is being rebuilt.',
        ],
    ];

    /** Reason mix. Most people say nothing useful; some say everything. */
    protected const REASON_WEIGHTS = [
        'none' => 30, 'other' => 16, 'not-have-that-feature' => 14,
        'found-better-plugin' => 13, 'is-not-working' => 11,
        'did-not-work-as-expected' => 8, 'could-not-understand' => 5,
        'looking-for-other' => 3,
    ];

    public function __construct(protected SiteReconciler $reconciler) {}

    /**
     * @param  array<int, string>  $releases  version history, oldest first
     */
    public function generate(
        Project $project,
        int $siteCount,
        int $weeks,
        array $releases,
        ?callable $onWeek = null,
        ?int $seed = null,
    ): void {
        /*
         * A seeded generator is reproducible, which matters twice: a demo
         * that looks different on every run is hard to talk about, and a
         * test asserting the population "has churn with written feedback"
         * is otherwise asserting a coin flip. Both showed up in practice --
         * that test failed once in a suite run and then passed five times.
         */
        if ($seed !== null) {
            mt_srand($seed);
        }

        // Note every random call below goes through mt_rand or array_rand,
        // both of which honour mt_srand. random_int() would not: it draws
        // from the CSPRNG and cannot be seeded, so a single use of it makes
        // the whole generator irreproducible -- which is exactly how the
        // flaky assertion survived the first attempt at this fix.

        $sites = $this->planSites($project, $siteCount, $weeks);

        // Captured once, before any mocking. Deriving each week from now()
        // inside the loop reads the clock we mocked last time round, so the
        // history walks backwards a week per week and ends up months adrift.
        $today = Carbon::now();

        for ($week = $weeks; $week >= 0; $week--) {
            $at = $today->copy()->subWeeks($week)->startOfWeek()->addHours(mt_rand(0, 167));

            // The final week must not land in the future, or the sites it
            // creates are invisible to a rollup that stops at today.
            if ($at->greaterThan($today)) {
                $at = $today->copy()->subHours(mt_rand(1, 20));
            }

            Carbon::setTestNow($at);

            $this->runWeek($project, $sites, $week, $weeks, $releases, $at);

            $onWeek && $onWeek($weeks - $week + 1, $weeks + 1);
        }

        Carbon::setTestNow();

        if ($seed !== null) {
            // Leave the global generator as we found it.
            mt_srand();
        }
    }

    /**
     * Decide each site's whole life up front -- when it arrives, whether it
     * ever leaves, how reliable its cron is. Deciding week by week would
     * produce a population with no memory, where churn is uncorrelated with
     * anything and every cohort behaves identically.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function planSites(Project $project, int $siteCount, int $weeks): array
    {
        $sites = [];

        for ($i = 0; $i < $siteCount; $i++) {
            // Adoption skews late: a growing plugin has more of its install
            // base arriving recently than at the start of the window.
            $arrival = (int) round($weeks * (1 - sqrt(mt_rand() / mt_getrandmax())));

            $sites[] = [
                'host' => $this->host($project, $i),
                'arrives_at_week' => min($arrival, $weeks),

                // ~14% churn over the window, and a churner leaves at some
                // point after it arrives rather than immediately.
                'leaves_at_week' => mt_rand() / mt_getrandmax() < 0.14
                    ? mt_rand(0, max(0, $arrival - 1))
                    : null,

                // A handful come back after leaving.
                'returns' => mt_rand() / mt_getrandmax() < 0.18,

                // Some sites have a broken or throttled wp-cron and simply
                // miss beats. Without these the "inactive" state never occurs.
                'reliability' => mt_rand() / mt_getrandmax() < 0.12
                    ? 0.45 + (mt_rand() / mt_getrandmax()) * 0.3
                    : 0.93 + (mt_rand() / mt_getrandmax()) * 0.07,

                'is_local' => mt_rand() / mt_getrandmax() < 0.04,
                'php' => $this->weighted(self::PHP_VERSIONS),
                'wp' => $this->weighted(self::WP_VERSIONS),
                'mysql' => $this->weighted(self::MYSQL_VERSIONS),
                'server' => $this->weighted(self::SERVERS),
                'locale' => $this->weighted(self::LOCALES),
                'country' => $this->weighted(self::COUNTRIES),
                'theme' => $this->weighted(array_fill_keys(array_keys(self::THEMES), 1)),
                'multisite' => mt_rand() / mt_getrandmax() < 0.03,
                'plugins' => $this->neighbourPlugins(),
                'users' => mt_rand(1, 40),
                'ip' => $this->ip(),

                // How eagerly this site takes plugin updates. Drives the
                // version-adoption curve, which is otherwise a step change.
                'upgrade_lag' => mt_rand(0, 6),
                'gone' => false,
            ];
        }

        return $sites;
    }

    protected function runWeek(Project $project, array &$sites, int $week, int $weeks, array $releases, Carbon $at): void
    {
        foreach ($sites as &$site) {
            if ($site['arrives_at_week'] < $week) {
                continue;
            }

            // The week it decided to leave.
            if ($site['leaves_at_week'] !== null && $site['leaves_at_week'] === $week && ! $site['gone']) {
                $this->deactivate($project, $site, $releases, $week, $weeks);
                $site['gone'] = true;

                continue;
            }

            if ($site['gone']) {
                if (! $site['returns'] || mt_rand() / mt_getrandmax() > 0.3) {
                    continue;
                }

                // A heartbeat after a deactivation is a reactivation, and the
                // reconciler is what has to work that out.
                $site['gone'] = false;
            }

            if (mt_rand() / mt_getrandmax() > $site['reliability']) {
                continue;
            }

            $this->track($project, $site, $releases, $week, $weeks);
        }

        unset($site);

        $this->skips($project, $week, $weeks);
    }

    protected function track(Project $project, array $site, array $releases, int $week, int $weeks): void
    {
        $this->reconcile($project, RawPayload::ROUTE_TRACK, $this->payload($project, $site, $releases, $week, $weeks));
    }

    protected function deactivate(Project $project, array $site, array $releases, int $week, int $weeks): void
    {
        $reason = $this->weighted(self::REASON_WEIGHTS);
        $comments = self::COMMENTS[$reason] ?? [];

        // Roughly a third of people who pick a reason also write something.
        $comment = $comments && mt_rand() / mt_getrandmax() < 0.34
            ? Arr::random($comments)
            : '';

        $this->reconcile($project, RawPayload::ROUTE_DEACTIVATE, $this->payload($project, $site, $releases, $week, $weeks) + [
            'reason_id' => $reason,
            'reason_info' => $comment,
        ]);
    }

    /**
     * Refusals of the opt-in dialog, sized so the opt-in rate lands in a
     * believable band rather than at a suspiciously round number.
     */
    protected function skips(Project $project, int $week, int $weeks): void
    {
        $arrivals = max(1, (int) round(($weeks - $week + 1) / 3));

        for ($i = 0; $i < mt_rand(0, $arrivals * 2); $i++) {
            TrackingSkip::acrossAccounts()->create([
                'account_id' => $project->account_id,
                'project_id' => $project->id,
                'previously_skipped' => mt_rand() / mt_getrandmax() < 0.25,
            ]);
        }
    }

    /**
     * The wire format, exactly as wp_remote_post would leave it: everything
     * a string, false as an empty string, nested arrays flattened by the
     * time Laravel has put them back together.
     */
    protected function payload(Project $project, array $site, array $releases, int $week, int $weeks): array
    {
        [$themeName, $themeVersion] = self::THEMES[$site['theme']];

        $roles = ['administrator' => (string) mt_rand(1, 3)];

        if ($site['users'] > 4) {
            $roles['subscriber'] = (string) max(0, $site['users'] - mt_rand(2, 4));
            $roles['editor'] = (string) mt_rand(1, 3);
        }

        return [
            'url' => 'https://'.$site['host'],
            'site' => Str::headline(Str::before($site['host'], '.')),
            'admin_email' => 'owner@'.$site['host'],
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'project_version' => $this->versionAt($site, $releases, $week, $weeks),
            'server' => [
                'software' => $site['server'],
                'php_version' => $site['php'],
                'mysql_version' => $site['mysql'],
            ],
            'wp' => [
                'version' => $site['wp'],
                'locale' => $site['locale'],
                'memory_limit' => Arr::random(['128M', '256M', '512M']),
                'debug_mode' => mt_rand() / mt_getrandmax() < 0.08 ? 'Yes' : 'No',
                'multisite' => $site['multisite'] ? 'Yes' : 'No',
                'theme_slug' => $site['theme'],
                'theme_name' => $themeName,
                'theme_version' => $themeVersion,
            ],
            'users' => ['total' => (string) $site['users']] + $roles,
            'plugins' => $site['plugins'],
            'active_plugins' => (string) (count($site['plugins']) + mt_rand(1, 6)),
            'inactive_plugins' => (string) mt_rand(0, 5),
            // 'undeclared' is here on purpose: it is not in the whitelist,
            // so every payload proves the reconciler drops what it must.
            'extra' => array_filter([
                'pro_version' => mt_rand() / mt_getrandmax() < 0.2 ? '1.4.0' : '',
                'undeclared' => 'dropped',
            ]),
            'is_local' => $site['is_local'] ? '1' : '',
            'tracking_skipped' => '',
            'client' => '2.0.4',
        ];
    }

    /**
     * Which release this site was running that week. Sites upgrade on a lag,
     * which is what turns a version chart into an adoption curve instead of
     * a cliff on release day.
     */
    protected function versionAt(array $site, array $releases, int $week, int $weeks): string
    {
        $elapsed = $weeks - $week;
        $perRelease = max(1, (int) floor($weeks / max(1, count($releases))));

        $index = (int) floor(($elapsed - $site['upgrade_lag']) / $perRelease);

        return $releases[max(0, min($index, count($releases) - 1))];
    }

    protected function reconcile(Project $project, string $route, array $data): void
    {
        $payload = RawPayload::acrossAccounts()->create([
            'account_id' => $project->account_id,
            'project_id' => $project->id,
            'route' => $route,
            'payload' => $data,
            'ip' => $this->ipFor($data['url']),
            'user_agent' => 'Appsero/'.md5($data['url']).';',
            'processed_at' => now(),
        ]);

        $this->reconciler->reconcile($payload);
    }

    protected function host(Project $project, int $index): string
    {
        $word = Str::slug(fake()->unique()->domainWord());
        $tld = Arr::random(['com', 'com', 'com', 'net', 'org', 'co.uk', 'de', 'io', 'shop', 'fr']);

        return "{$word}-{$index}.{$tld}";
    }

    /** Stable per host, so a site keeps its address across weeks. */
    protected function ipFor(string $url): string
    {
        $seed = crc32(SiteUrl::key($url));

        return sprintf('%d.%d.%d.%d', 23 + $seed % 180, ($seed >> 8) % 256, ($seed >> 16) % 256, ($seed >> 24) % 254 + 1);
    }

    protected function ip(): string
    {
        return sprintf('%d.%d.%d.%d', mt_rand(23, 203), mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254));
    }

    protected function neighbourPlugins(): array
    {
        $slugs = (array) array_rand(self::NEIGHBOUR_PLUGINS, mt_rand(2, 7));

        $plugins = [];

        foreach ($slugs as $slug) {
            [$name, $version] = self::NEIGHBOUR_PLUGINS[$slug];
            $plugins[$slug] = ['name' => $name, 'version' => $version];
        }

        return $plugins;
    }

    /** @param  array<string, int>  $weights */
    protected function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }

    /**
     * The whitelist has to exist before the payloads arrive, or every
     * extra[] key is dropped and the metadata screen has nothing to show.
     */
    public function registerMetaFields(Project $project): void
    {
        ProjectMetaField::acrossAccounts()->firstOrCreate(
            ['project_id' => $project->id, 'key' => 'pro_version'],
            ['account_id' => $project->account_id, 'datatype' => ProjectMetaField::TYPE_STRING, 'label' => 'Pro version'],
        );
    }
}

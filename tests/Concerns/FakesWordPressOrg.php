<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Stubs for the wordpress.org endpoints the panel reads.
 *
 * The repository dashboard fetches the download summary live on render --
 * see RepoDownloads for why -- so every test that renders that page needs
 * this, or the request is refused by TestCase's stray-request guard.
 *
 * Defaults are metadata-viewer's real figures on the day this was written.
 * Real numbers rather than round ones, because 10 / 9 / 63 / 4,468 are
 * checkable against the plugin's own advanced page, and a test built on
 * 1 / 2 / 3 / 4 cannot catch a window that is off by a day.
 */
trait FakesWordPressOrg
{
    /** @var array<string, int|null> */
    protected array $wporgSummary = [
        'today' => 10,
        'yesterday' => 9,
        'last_week' => 63,
        'all_time' => 4468,
    ];

    protected bool $wporgFaked = false;

    /**
     * Set (or revise) what the summary endpoint returns.
     *
     * Registered once, behind a closure reading the current values, so a
     * test can call this after setUp already has and actually change the
     * answer. A second Http::fake() would not: stubs are appended, the
     * first matching pattern wins, and the revision would be silently
     * ignored -- which is exactly the kind of test that passes while
     * asserting nothing.
     *
     * @param  array<string, int|null>  $summary
     */
    protected function fakeDownloadSummary(array $summary = []): void
    {
        $this->wporgSummary = array_merge($this->wporgSummary, $summary);

        if ($this->wporgFaked) {
            return;
        }

        $this->wporgFaked = true;

        /*
         * The summary pattern comes first because historical_summary is a
         * query parameter on downloads.php -- the daily-series pattern
         * would otherwise swallow it and return a series where a summary
         * was expected.
         */
        Http::fake([
            'api.wordpress.org/stats/plugin/1.0/downloads.php*historical_summary*' => fn () => Http::response(
                // Every figure arrives from wordpress.org as a string.
                array_map(fn ($value) => $value === null ? null : (string) $value, $this->wporgSummary),
            ),
            'api.wordpress.org/*' => Http::response([]),
            'plugins.svn.wordpress.org/*' => Http::response('', 404),
        ]);
    }

    /** wordpress.org unreachable, so the page must fall back rather than fail. */
    protected function fakeWordPressOrgDown(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
    }
}

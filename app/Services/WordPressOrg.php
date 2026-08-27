<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Everything this application knows about the public repository.
 *
 * All four endpoints are unauthenticated and public. We are a guest on
 * them: every call is rate-limited from our side, identifies itself
 * honestly in the User-Agent, and fails soft. A repository that is slow or
 * briefly down must never take the dashboard with it -- these methods
 * return null or an empty array and let the caller record nothing for the
 * day, which is recoverable. Throwing here would fail the scheduled job,
 * retry it, and hammer a service that is already struggling.
 *
 * Note what is deliberately absent: any write, any authenticated call, and
 * any scraping of pages meant for humans. Everything below is an API
 * wordpress.org publishes for exactly this purpose.
 */
class WordPressOrg
{
    protected const INFO = 'https://api.wordpress.org/plugins/info/1.2/';

    protected const STATS = 'https://api.wordpress.org/stats/plugin/1.0/';

    protected const SVN = 'https://plugins.svn.wordpress.org/';

    /** How many search pages deep to look before calling a plugin unranked. */
    public const RANK_DEPTH = 100;

    protected function request(): PendingRequest
    {
        return Http::timeout(20)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->withUserAgent(sprintf(
                'DigiTrackerSuite/1.0 (+%s)',
                config('app.url'),
            ));
    }

    /**
     * The full public record for one plugin.
     *
     * @return array<string, mixed>|null null when the slug is unknown or the
     *                                   repository could not be reached
     */
    public function plugin(string $slug): ?array
    {
        $response = $this->request()->get(self::INFO, [
            'action' => 'plugin_information',
            'request' => ['slug' => $slug],
        ]);

        if (! $response->successful()) {
            return $this->miss('plugin_information', $slug, $response->status());
        }

        $data = $response->json();

        /*
         * A missing slug answers 200 with {"error": "..."} rather than a
         * 404, so the status code alone is not enough to tell "no such
         * plugin" from "here it is".
         */
        if (! is_array($data) || isset($data['error']) || empty($data['slug'])) {
            return $this->miss('plugin_information', $slug, 'payload carried an error');
        }

        return $data;
    }

    /**
     * Daily download counts, oldest first.
     *
     * The tail is provisional: wordpress.org revises recent days as mirrors
     * report in, which is why callers upsert rather than insert.
     *
     * @return array<string, int> date (Y-m-d) => downloads
     */
    public function dailyDownloads(string $slug, int $days = 730): array
    {
        $response = $this->request()->get(self::STATS.'downloads.php', [
            'slug' => $slug,
            'limit' => $days,
        ]);

        if (! $response->successful()) {
            $this->miss('downloads', $slug, $response->status());

            return [];
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        $counts = [];

        foreach ($data as $date => $downloads) {
            // The endpoint has been seen returning [] for a plugin with no
            // history, which decodes to a list rather than a map.
            if (! is_string($date) || ! is_numeric($downloads)) {
                continue;
            }

            $counts[$date] = (int) $downloads;
        }

        return $counts;
    }

    /**
     * The repository's own version split, as percentages of installs.
     *
     * Not comparable to our by_version counts, and worth keeping separate
     * for that reason: this describes everybody, ours describes only the
     * sites that opted in.
     *
     * @return array<string, float> version => percentage
     */
    public function versionDistribution(string $slug): array
    {
        $response = $this->request()->get(self::STATS, ['slug' => $slug]);

        if (! $response->successful()) {
            $this->miss('version stats', $slug, $response->status());

            return [];
        }

        $data = $response->json();

        return is_array($data)
            ? array_map(fn ($share) => round((float) $share, 2), $data)
            : [];
    }

    /**
     * Release dates for every tagged version, read from Subversion.
     *
     * This is the one genuinely non-obvious call here. wordpress.org
     * publishes no release-date API, and the tags directory listing shows
     * names without dates -- but the Subversion server answers a WebDAV
     * PROPFIND with a creation date per tag, so a single request recovers
     * the entire release history rather than only what we observe from
     * today onward.
     *
     * @return array<string, CarbonImmutable> version => release date
     */
    public function releaseDates(string $slug): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .'<propfind xmlns="DAV:"><prop><creationdate/></prop></propfind>';

        $response = $this->request()
            ->withHeaders(['Depth' => '1', 'Content-Type' => 'text/xml'])
            ->send('PROPFIND', self::SVN.rawurlencode($slug).'/tags/', ['body' => $body]);

        if (! $response->successful()) {
            $this->miss('svn tags', $slug, $response->status());

            return [];
        }

        return $this->parseTagDates($response->body(), $slug);
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    protected function parseTagDates(string $xml, string $slug): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return [];
        }

        $document->registerXPathNamespace('D', 'DAV:');

        $releases = [];

        foreach ($document->xpath('//D:response') ?: [] as $node) {
            $node->registerXPathNamespace('D', 'DAV:');

            $href = (string) ($node->xpath('D:href')[0] ?? '');
            $created = (string) ($node->xpath('.//D:creationdate')[0] ?? '');

            if ($href === '' || $created === '') {
                continue;
            }

            // /{slug}/tags/3.3.1/ -- the tags directory itself has no
            // version and must not become a release.
            if (! preg_match('#/tags/([^/]+)/?$#', rawurldecode($href), $matches)) {
                continue;
            }

            $version = trim($matches[1]);

            if ($version === '' || $version === 'tags') {
                continue;
            }

            $releases[$version] = CarbonImmutable::parse($created);
        }

        return $releases;
    }

    /**
     * Where a slug ranks in repository search for a term.
     *
     * Returns null for "not found within `$depth`", which is deliberately
     * distinct from a large number. A plugin outside the window has no
     * position, and inventing one would put a fabricated value on a chart.
     *
     * @return array{position: int|null, depth: int, total: int|null}
     */
    public function searchPosition(string $slug, string $keyword, int $depth = self::RANK_DEPTH): array
    {
        $perPage = 100;
        $pages = (int) ceil($depth / $perPage);
        $total = null;
        $seen = 0;

        for ($page = 1; $page <= $pages; $page++) {
            $response = $this->request()->get(self::INFO, [
                'action' => 'query_plugins',
                'request' => [
                    'search' => $keyword,
                    'page' => $page,
                    'per_page' => $perPage,
                    // The listing is long; asking for less of each entry
                    // keeps a hundred results to a sane response size.
                    'fields' => [
                        'short_description' => false,
                        'sections' => false,
                        'banners' => false,
                        'icons' => false,
                        'screenshots' => false,
                        'ratings' => false,
                        'description' => false,
                    ],
                ],
            ]);

            if (! $response->successful()) {
                $this->miss('query_plugins', $keyword, $response->status());
                break;
            }

            $data = $response->json();
            $plugins = $data['plugins'] ?? [];

            $total ??= isset($data['info']['results']) ? (int) $data['info']['results'] : null;

            if (! is_array($plugins) || $plugins === []) {
                break;
            }

            foreach ($plugins as $index => $plugin) {
                $seen++;

                if (($plugin['slug'] ?? null) === $slug) {
                    return [
                        'position' => ($page - 1) * $perPage + $index + 1,
                        'depth' => $depth,
                        'total' => $total,
                    ];
                }
            }

            if (count($plugins) < $perPage) {
                break;
            }
        }

        return ['position' => null, 'depth' => min($depth, max($seen, 1)), 'total' => $total];
    }

    protected function miss(string $endpoint, string $subject, int|string $reason): null
    {
        Log::info('wordpress.org lookup failed', [
            'endpoint' => $endpoint,
            'subject' => $subject,
            'reason' => $reason,
        ]);

        return null;
    }
}

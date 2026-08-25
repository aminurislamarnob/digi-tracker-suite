<?php

namespace App\Support;

/**
 * Canonicalises a WordPress home URL into a stable site identity.
 *
 * The protocol sends no site ID, so the URL is all we have. A site that
 * moves from http to https, adds or drops www, or reports with a default
 * port must not read as a new install -- otherwise every count inflates
 * and churn looks like growth.
 */
class SiteUrl
{
    public static function canonicalise(string $url): string
    {
        $url = trim($url);

        if (! str_contains($url, '://')) {
            $url = 'http://'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $host = strtolower($parts['host']);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $port = $parts['port'] ?? null;

        // Default ports carry no identity; a non-default one does.
        if ($port && ! in_array([$scheme, (int) $port], [['http', 80], ['https', 443]], true)) {
            $host .= ':'.$port;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        // Query strings and fragments are never part of a home URL's identity.
        return $host.$path;
    }

    public static function key(string $url): string
    {
        return sha1(static::canonicalise($url));
    }
}

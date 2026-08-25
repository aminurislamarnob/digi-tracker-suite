<?php

namespace Tests\Unit;

use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteUrlTest extends TestCase
{
    public static function equivalentUrls(): array
    {
        return [
            'scheme differs' => ['http://example.com', 'https://example.com'],
            'www prefix' => ['https://www.example.com', 'https://example.com'],
            'trailing slash' => ['https://example.com/', 'https://example.com'],
            'host casing' => ['https://EXAMPLE.com', 'https://example.com'],
            'default https port' => ['https://example.com:443', 'https://example.com'],
            'default http port' => ['http://example.com:80', 'https://example.com'],
            'query string' => ['https://example.com/?utm=x', 'https://example.com'],
            'all at once' => ['HTTP://WWW.Example.com:80/', 'https://example.com'],
        ];
    }

    #[DataProvider('equivalentUrls')]
    public function test_equivalent_urls_share_a_key(string $a, string $b): void
    {
        $this->assertSame(SiteUrl::key($b), SiteUrl::key($a), "[$a] should match [$b]");
    }

    public static function distinctUrls(): array
    {
        return [
            'different host' => ['https://example.com', 'https://example.org'],
            'subdomain' => ['https://example.com', 'https://blog.example.com'],
            'subdirectory install' => ['https://example.com', 'https://example.com/blog'],
            'non default port' => ['https://example.com', 'https://example.com:8443'],
        ];
    }

    #[DataProvider('distinctUrls')]
    public function test_genuinely_different_sites_do_not_collide(string $a, string $b): void
    {
        $this->assertNotSame(SiteUrl::key($b), SiteUrl::key($a), "[$a] should differ from [$b]");
    }

    public function test_a_subdirectory_install_keeps_its_path(): void
    {
        $this->assertSame('example.com/blog', SiteUrl::canonicalise('https://www.example.com/blog/'));
    }

    public function test_a_bare_host_is_handled(): void
    {
        $this->assertSame('example.com', SiteUrl::canonicalise('example.com'));
    }
}

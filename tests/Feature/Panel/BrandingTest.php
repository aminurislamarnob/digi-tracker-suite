<?php

namespace Tests\Feature\Panel;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Tests\TestCase;

/**
 * Branding fails quietly: a missing file leaves a broken image on the
 * sign-in screen and nothing in the log, and the deploy still reports
 * success. These assert the files are actually present and shaped the way
 * the panel assumes.
 */
class BrandingTest extends TestCase
{
    protected function panel(): Panel
    {
        return Filament::getPanel('admin');
    }

    /** @return array{0: int, 1: int} */
    protected function dimensions(string $relative): array
    {
        $path = public_path($relative);

        $this->assertFileExists($path, "{$relative} is missing -- the panel references it by name.");

        [$width, $height] = getimagesize($path);

        return [$width, $height];
    }

    public function test_both_wordmarks_ship_and_share_the_same_proportions(): void
    {
        [$lightW, $lightH] = $this->dimensions('images/pluginizelab-logo.png');
        [$darkW, $darkH] = $this->dimensions('images/pluginizelab-logo-dark.png');

        // Height drives the rendered width, so differing ratios would make
        // the logo visibly jump on switching theme.
        $this->assertSame($lightW / $lightH, $darkW / $darkH);
    }

    /**
     * A favicon cropped from a 4.8:1 wordmark is an illegible smear, so the
     * square mark is what must be there.
     */
    public function test_the_favicon_is_square(): void
    {
        [$width, $height] = $this->dimensions('images/favicon-32.png');

        $this->assertSame($width, $height);
    }

    public function test_the_panel_serves_each_brand_asset_over_http(): void
    {
        $panel = $this->panel();

        $urls = [
            $panel->getBrandLogo(),
            $panel->getDarkModeBrandLogo(),
            $panel->getFavicon(),
        ];

        foreach ($urls as $url) {
            $this->assertNotEmpty($url);

            // asset() yields an absolute URL; what matters is that the path
            // it points at resolves to a file inside the served directory.
            $this->assertFileExists(
                public_path(parse_url((string) $url, PHP_URL_PATH)),
                "The panel points at {$url}, which is not on disk.",
            );
        }
    }

    /**
     * The dark variant exists solely because the wordmark is near-black.
     * Wiring both to the same file would look correct in a config diff and
     * wrong on screen.
     */
    public function test_dark_mode_does_not_reuse_the_light_wordmark(): void
    {
        $panel = $this->panel();

        $this->assertNotSame($panel->getBrandLogo(), $panel->getDarkModeBrandLogo());
    }

    /**
     * The primary colour is sampled from the logo's mark. Filament's default
     * Indigo sits noticeably purple beside it, so every button on every
     * screen would quietly disagree with the brand.
     */
    public function test_the_primary_colour_is_the_brand_blue_and_not_the_default(): void
    {
        $primary = $this->panel()->getColors()['primary'];

        $this->assertSame(Color::hex('#195CE3')[600], $primary[600] ?? null);
        $this->assertNotSame(Color::Indigo[600], $primary[600] ?? null);
    }
}

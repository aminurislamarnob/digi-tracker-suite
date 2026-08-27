<?php

namespace Tests\Unit;

use App\Services\RepoAnalytics;
use PHPUnit\Framework\TestCase;

/**
 * The derived numbers.
 *
 * Every one of these has a wrong answer that renders perfectly well: a
 * regression line through three points, a smoothing pass that invents
 * values at the edges, an opt-in rate of 0% that actually means "we have no
 * public figure". A chart cannot tell you it is lying, so the arithmetic is
 * pinned here instead.
 */
class RepoAnalyticsTest extends TestCase
{
    protected RepoAnalytics $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = new RepoAnalytics;
    }

    public function test_a_rising_series_is_reported_as_rising(): void
    {
        $trend = $this->analytics->trend([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertSame('rising', $trend['direction']);
        $this->assertGreaterThan(0, $trend['slope']);
    }

    public function test_a_falling_series_is_reported_as_falling(): void
    {
        $trend = $this->analytics->trend([10, 9, 8, 7, 6, 5, 4, 3, 2, 1]);

        $this->assertSame('falling', $trend['direction']);
        $this->assertLessThan(0, $trend['slope']);
    }

    /**
     * A line through three points looks authoritative and means nothing.
     * Refusing to draw one is the whole point.
     */
    public function test_too_few_points_produce_no_line_at_all(): void
    {
        $trend = $this->analytics->trend([5, 9, 2]);

        $this->assertSame('flat', $trend['direction']);
        $this->assertSame([null, null, null], $trend['values']);
    }

    /** Downloads cannot be negative, so a steep decline clamps at the axis. */
    public function test_a_falling_line_never_goes_below_zero(): void
    {
        $trend = $this->analytics->trend([100, 80, 60, 40, 20, 10, 5, 1]);

        foreach ($trend['values'] as $value) {
            $this->assertGreaterThanOrEqual(0, $value);
        }
    }

    public function test_the_baseline_smooths_a_spike_without_erasing_it(): void
    {
        $flat = array_fill(0, 41, 10);
        $flat[20] = 200;

        $baseline = $this->analytics->baseline($flat);

        // Pulled up at the spike, but nowhere near the raw value.
        $this->assertGreaterThan(10, $baseline[20]);
        $this->assertLessThan(200, $baseline[20]);

        // Neighbours feel it; distant points barely do.
        $this->assertGreaterThan($baseline[30], $baseline[22]);
    }

    /**
     * A boxcar mean turns one big day into a plateau exactly as wide as the
     * window, which reads as a sustained rise that never happened. Gaussian
     * weighting is what avoids that, so assert the shape decays.
     */
    public function test_the_baseline_decays_with_distance_rather_than_plateauing(): void
    {
        $flat = array_fill(0, 41, 0);
        $flat[20] = 100;

        $baseline = $this->analytics->baseline($flat);

        $this->assertGreaterThan($baseline[21], $baseline[20]);
        $this->assertGreaterThan($baseline[22], $baseline[21]);
        $this->assertGreaterThan($baseline[23], $baseline[22]);
    }

    public function test_gaps_in_the_series_stay_gaps(): void
    {
        $baseline = $this->analytics->baseline([1, 2, null, 4, 5]);

        $this->assertNull($baseline[2]);
    }

    public function test_momentum_reports_rate_and_change_in_rate(): void
    {
        $accelerating = [];

        for ($i = 0; $i < 30; $i++) {
            $accelerating[] = $i ** 2;
        }

        $momentum = $this->analytics->momentum($accelerating);

        $velocities = array_values(array_filter($momentum['velocity'], fn ($v) => $v !== null));
        $accelerations = array_values(array_filter($momentum['acceleration'], fn ($v) => $v !== null));

        $this->assertNotEmpty($velocities);
        $this->assertNotEmpty($accelerations);

        /*
         * Asserted on the interior, not the tail. The smoothing kernel is
         * truncated within a window of either end, which drags the final
         * points of a rising series downward -- so the last acceleration is
         * genuinely negative here despite the input accelerating throughout.
         * That is a real property of the estimator, documented on
         * RepoAnalytics::baseline(), not something to assert away.
         */
        $mid = intdiv(count($velocities), 2);

        $this->assertGreaterThan($velocities[2], $velocities[$mid]);
        $this->assertGreaterThan(0, $accelerations[$mid]);
    }

    /** The first point has nothing to differ from, and must not read as 0. */
    public function test_momentum_has_no_opinion_about_the_first_point(): void
    {
        $momentum = $this->analytics->momentum([1, 2, 3, 4, 5, 6, 7, 8]);

        $this->assertNull($momentum['velocity'][0]);
        $this->assertNull($momentum['acceleration'][0]);
    }

    public function test_the_opt_in_rate_is_a_share_of_the_public_figure(): void
    {
        $this->assertSame(25.0, $this->analytics->optInRate(125, 500));
        $this->assertSame(4.0, $this->analytics->optInRate(400, 10000));
    }

    /**
     * The distinction that keeps this number honest. Zero would claim
     * nobody opted in; null says we have nothing to compare against.
     */
    public function test_no_public_figure_yields_null_rather_than_zero(): void
    {
        $this->assertNull($this->analytics->optInRate(125, null));
        $this->assertNull($this->analytics->optInRate(125, 0));
    }

    /**
     * active_installs is published in rounded buckets, so tracked installs
     * can legitimately exceed it. Reporting 118% would look like a bug and
     * discredit the surrounding numbers.
     */
    public function test_the_rate_is_capped_at_one_hundred_percent(): void
    {
        $this->assertSame(100.0, $this->analytics->optInRate(590, 500));
    }
}

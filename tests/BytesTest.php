<?php

declare(strict_types=1);

namespace Tests;

use Catualus\GmodToolkit\Support\Bytes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Size formatting.
 *
 * Worth testing because the same numbers appear in a table cell, a chart axis and a
 * stat tile, and a chart that disagrees with the row beside it is worse than no
 * chart at all.
 */
final class BytesTest extends TestCase
{
    #[Test]
    public function it_picks_a_unit_that_leaves_a_readable_number(): void
    {
        $this->assertSame('0 B', Bytes::human(0));
        $this->assertSame('512 B', Bytes::human(512));
        $this->assertSame('1.0 KB', Bytes::human(1024));
        $this->assertSame('1.0 MB', Bytes::human(1048576));
        $this->assertSame('1.4 GB', Bytes::human(1503238553));
    }

    #[Test]
    public function bytes_are_never_shown_with_decimals(): void
    {
        // Half a byte is not a thing, so the smallest unit is always whole.
        $this->assertSame('999 B', Bytes::human(999));
    }

    #[Test]
    public function large_values_in_a_unit_drop_their_decimal(): void
    {
        // "512 MB" reads better than "512.0 MB" and is no less precise.
        $this->assertSame('512 MB', Bytes::human(512 * 1048576));
    }

    #[Test]
    public function negative_and_zero_sizes_are_reported_as_zero(): void
    {
        $this->assertSame('0 B', Bytes::human(-1));
    }

    #[Test]
    public function it_stops_at_terabytes_rather_than_inventing_a_unit(): void
    {
        $this->assertStringEndsWith('TB', Bytes::human(1024 ** 5));
    }

    #[Test]
    public function megabytes_are_rounded_for_chart_axes(): void
    {
        $this->assertSame(1.0, Bytes::toMegabytes(1048576));
        $this->assertSame(1.5, Bytes::toMegabytes(1572864));
    }

    #[Test]
    public function download_time_is_computed_in_megabits(): void
    {
        // 100 megabits of payload at 100 Mbps is one second.
        $this->assertEqualsWithDelta(1.0, Bytes::downloadSeconds(12_500_000, 100), 0.001);
    }

    #[Test]
    public function a_zero_line_speed_does_not_divide_by_zero(): void
    {
        $this->assertSame(0.0, Bytes::downloadSeconds(1000, 0));
    }

    #[Test]
    public function durations_are_deliberately_coarse(): void
    {
        $this->assertSame('under a second', Bytes::humanDuration(0.4));
        $this->assertSame('45 s', Bytes::humanDuration(45));
        $this->assertSame('4 min 12 s', Bytes::humanDuration(252));
        $this->assertSame('1 h 30 min', Bytes::humanDuration(5400));
    }
}

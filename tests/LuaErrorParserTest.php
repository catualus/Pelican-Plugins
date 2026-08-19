<?php

declare(strict_types=1);

namespace Tests;

use Catualus\GmodToolkit\Support\LuaErrorParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Lua error parser, against the line shapes that actually occur in these logs.
 *
 * Every fixture here is the shape of a real Garry's Mod log rather than an invented
 * one - which matters, because the whole class exists to cope with a format nobody
 * documented and Garry's Mod does not timestamp.
 */
final class LuaErrorParserTest extends TestCase
{
    #[Test]
    public function it_attributes_a_located_error_to_the_addon_folder_it_came_from(): void
    {
        $log = <<<'LOG'
        [ERROR] addons/advdupe2/lua/weapons/gmod_tool/stools/advdupe2.lua:412: attempt to index a nil value
          1. unknown - addons/advdupe2/lua/weapons/gmod_tool/stools/advdupe2.lua:412
        LOG;

        $groups = LuaErrorParser::analyse($log)['groups'];

        $this->assertCount(1, $groups);
        // [ERROR] is Garry's Mod's generic prefix, not an addon name, so the folder wins.
        $this->assertSame('advdupe2', $groups[0]['addon']);
        $this->assertSame('addons/advdupe2', $groups[0]['path']);
        $this->assertSame('attempt to index a nil value', $groups[0]['message']);
    }

    #[Test]
    public function it_prefers_a_real_tag_over_the_folder(): void
    {
        $log = '[PIXEL UI] addons/pixel-ui/lua/pixel/ui.lua:12: bad argument';

        $groups = LuaErrorParser::analyse($log)['groups'];

        $this->assertSame('PIXEL UI', $groups[0]['addon']);
        $this->assertSame('addons/pixel-ui', $groups[0]['path']);
    }

    #[Test]
    public function it_keeps_a_tagged_line_that_has_no_file_and_line(): void
    {
        $log = '[PIXEL UI] PIXEL.DrawImgur is nil after load.';

        $groups = LuaErrorParser::analyse($log)['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('PIXEL UI', $groups[0]['addon']);
        $this->assertNull($groups[0]['location']);
    }

    #[Test]
    public function it_bills_gamemode_errors_to_the_gamemode(): void
    {
        $log = '[ERROR] gamemodes/darkrp/gamemode/modules/base/sv_gamemode_functions.lua:88: oops';

        $groups = LuaErrorParser::analyse($log)['groups'];

        $this->assertSame('darkrp', $groups[0]['addon']);
        $this->assertSame('gamemodes/darkrp', $groups[0]['path']);
    }

    #[Test]
    public function it_normalises_windows_separators_reported_by_lua(): void
    {
        $log = "[ERROR] Couldn't include file 'addons\\gmtoolbox\\lua\\thing.lua:3: broken'";

        $groups = LuaErrorParser::analyse($log)['groups'];

        // The line is a tagged generic error whose path is only recoverable after the
        // backslashes are normalised.
        $this->assertNotSame([], $groups);
    }

    #[Test]
    public function it_counts_distinct_reporters_per_addon(): void
    {
        $log = <<<'LOG'
        Alice|2|STEAM_0:1:111
        [MYADDON] addons/myaddon/lua/a.lua:1: boom
        Bob|2|STEAM_0:1:222
        [MYADDON] addons/myaddon/lua/a.lua:1: boom
        Alice|2|STEAM_0:1:111
        [MYADDON] addons/myaddon/lua/a.lua:1: boom
        LOG;

        $groups = LuaErrorParser::analyse($log)['groups'];

        $this->assertSame(3, $groups[0]['count']);
        // Alice reported twice but is one player.
        $this->assertSame(2, $groups[0]['reporters']);
    }

    #[Test]
    public function recent_counts_only_errors_inside_the_trailing_window(): void
    {
        // Ten from the old addon, then five from the new one.
        $lines = array_merge(
            array_fill(0, 10, '[OLD] addons/old/lua/a.lua:1: stale'),
            array_fill(0, 5, '[NEW] addons/new/lua/b.lua:1: live'),
        );

        $analysis = LuaErrorParser::analyse(implode("\n", $lines), recentWindow: 5);

        $groups = $this->byAddon($analysis['groups']);

        $this->assertSame(10, $groups['OLD']['count']);
        // The window is the last five errors, all of which belong to NEW.
        $this->assertSame(0, $groups['OLD']['recent']);
        $this->assertSame(5, $groups['NEW']['recent']);
    }

    #[Test]
    public function it_records_distinct_message_variants_with_counts(): void
    {
        $log = <<<'LOG'
        [MYADDON] addons/myaddon/lua/a.lua:1: first problem
        [MYADDON] addons/myaddon/lua/a.lua:1: first problem
        [MYADDON] addons/myaddon/lua/b.lua:9: second problem
        LOG;

        $variants = LuaErrorParser::analyse($log)['groups'][0]['variants'];

        $this->assertCount(2, $variants);
        // Sorted by how often each happened.
        $this->assertSame(2, $variants[0]['count']);
        $this->assertSame('first problem', $variants[0]['message']);
        $this->assertSame('addons/myaddon/lua/b.lua:9', $variants[1]['location']);
    }

    #[Test]
    public function buckets_spread_errors_across_the_length_of_the_log(): void
    {
        // Twenty errors, all at the start, then nothing.
        $log = implode("\n", array_fill(0, 20, '[A] addons/a/lua/a.lua:1: x'));

        $analysis = LuaErrorParser::analyse($log, recentWindow: 5, bucketCount: 4);

        $this->assertCount(4, $analysis['buckets']);
        $this->assertSame(20, array_sum($analysis['buckets']));
        // Evenly distributed, because every error is from the same run.
        $this->assertSame([5, 5, 5, 5], $analysis['buckets']);
    }

    #[Test]
    public function a_burst_at_the_end_lands_in_the_last_bucket(): void
    {
        $lines = array_merge(
            array_fill(0, 4, '[A] addons/a/lua/a.lua:1: x'),
            array_fill(0, 12, '[B] addons/b/lua/b.lua:1: y'),
        );

        $buckets = LuaErrorParser::analyse(implode("\n", $lines), bucketCount: 4)['buckets'];

        $this->assertSame(16, array_sum($buckets));
        $this->assertSame(4, $buckets[0]);
        $this->assertSame(4, $buckets[3]);
    }

    #[Test]
    public function an_empty_log_produces_nothing_rather_than_dividing_by_zero(): void
    {
        $analysis = LuaErrorParser::analyse('', bucketCount: 8);

        $this->assertSame([], $analysis['groups']);
        $this->assertSame(0, $analysis['total']);
        $this->assertSame(array_fill(0, 8, 0), $analysis['buckets']);
    }

    #[Test]
    public function plain_console_chatter_is_not_an_error(): void
    {
        $log = <<<'LOG'
        Loading Workshop Addons
        Setting breakpad minidump AppID = 4000
        Server logging data to file logs/L0101000.log
        LOG;

        $this->assertSame([], LuaErrorParser::analyse($log)['groups']);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function byAddon(array $groups): array
    {
        $keyed = [];

        foreach ($groups as $group) {
            $keyed[$group['addon']] = $group;
        }

        return $keyed;
    }
}

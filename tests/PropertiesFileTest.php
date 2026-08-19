<?php

declare(strict_types=1);

namespace Tests;

use Catualus\MinecraftToolkit\Support\PropertiesFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server.properties editor.
 *
 * Minecraft writes this file with java.util.Properties.store, which escapes `=`,
 * `:`, `#`, `!` and backslashes, and stamps a dated comment header. The class under
 * test keeps every line it was not asked to change, so most of what is checked here
 * is what does *not* happen on save.
 */
final class PropertiesFileTest extends TestCase
{
    /** A real header and a real escaped value, as Minecraft writes them. */
    private const REAL = <<<'PROPS'
    #Minecraft server properties
    #Sun Aug 17 09:14:22 UTC 2026
    enable-jmx-monitoring=false
    level-name=world
    level-type=minecraft\:normal
    motd=A Minecraft Server
    max-players=20
    view-distance=10
    PROPS;

    #[Test]
    public function an_untouched_file_round_trips_unchanged(): void
    {
        $this->assertSame(
            self::REAL . "\n",
            PropertiesFile::parse(self::REAL)->merge([])->render(),
        );
    }

    #[Test]
    public function it_unescapes_javas_colon_escaping_when_reading(): void
    {
        $values = PropertiesFile::parse(self::REAL)->all();

        $this->assertSame('minecraft:normal', $values['level-type']);
    }

    #[Test]
    public function it_re_escapes_on_the_way_back_out(): void
    {
        $rendered = PropertiesFile::parse(self::REAL)
            ->merge(['level-type' => 'minecraft:flat'])
            ->render();

        $this->assertStringContainsString('level-type=minecraft\\:flat', $rendered);
    }

    #[Test]
    public function the_dated_header_survives_a_save(): void
    {
        $rendered = PropertiesFile::parse(self::REAL)->merge(['max-players' => '40'])->render();

        $this->assertStringContainsString('#Minecraft server properties', $rendered);
        $this->assertStringContainsString('#Sun Aug 17 09:14:22 UTC 2026', $rendered);
    }

    #[Test]
    public function only_the_changed_key_is_rewritten(): void
    {
        $rendered = PropertiesFile::parse(self::REAL)->merge(['max-players' => '40'])->render();

        $this->assertStringContainsString('max-players=40', $rendered);
        // Untouched, so its original escaping is still there rather than re-derived.
        $this->assertStringContainsString('level-type=minecraft\\:normal', $rendered);
    }

    #[Test]
    public function writing_the_same_value_back_changes_nothing(): void
    {
        $rendered = PropertiesFile::parse(self::REAL)->merge(['level-type' => 'minecraft:normal'])->render();

        $this->assertSame(self::REAL . "\n", $rendered);
    }

    #[Test]
    public function it_splits_on_a_colon_separator_as_java_does(): void
    {
        $values = PropertiesFile::parse('some-key:some-value')->all();

        $this->assertSame(['some-key' => 'some-value'], $values);
    }

    #[Test]
    public function it_escapes_characters_that_would_break_the_file(): void
    {
        $rendered = PropertiesFile::parse('motd=old')
            ->merge(['motd' => 'Welcome! #1 server: come in'])
            ->render();

        $this->assertSame("motd=Welcome\\! \\#1 server\\: come in\n", $rendered);
    }

    #[Test]
    public function an_escaped_value_survives_a_full_round_trip(): void
    {
        $value = 'has = and : and # and ! and \\ in it';

        $rendered = PropertiesFile::parse('k=x')->merge(['k' => $value])->render();
        $reparsed = PropertiesFile::parse($rendered)->all();

        $this->assertSame($value, $reparsed['k']);
    }

    #[Test]
    public function it_decodes_unicode_escapes(): void
    {
        $values = PropertiesFile::parse('motd=caf\\u00e9')->all();

        $this->assertSame('café', $values['motd']);
    }

    #[Test]
    public function blank_lines_and_bang_comments_are_preserved(): void
    {
        $raw = "!an old style comment\n\nkey=value\n\n# another\n";

        $this->assertSame($raw, PropertiesFile::parse($raw)->merge([])->render());
    }

    #[Test]
    public function a_line_with_no_separator_is_kept_but_is_not_a_setting(): void
    {
        $raw = "junk-line-with-no-separator\nreal=value\n";

        $this->assertSame(['real' => 'value'], PropertiesFile::parse($raw)->all());
        $this->assertSame($raw, PropertiesFile::parse($raw)->merge([])->render());
    }

    #[Test]
    public function has_reports_whether_a_key_is_defined(): void
    {
        $file = PropertiesFile::parse(self::REAL);

        $this->assertTrue($file->has('motd'));
        $this->assertFalse($file->has('nonexistent'));
    }

    #[Test]
    public function a_crlf_file_does_not_leave_carriage_returns_in_values(): void
    {
        $values = PropertiesFile::parse("a=1\r\nb=2\r\n")->all();

        $this->assertSame(['a' => '1', 'b' => '2'], $values);
    }
}

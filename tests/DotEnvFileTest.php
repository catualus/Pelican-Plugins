<?php

declare(strict_types=1);

namespace Tests;

use Catualus\AppToolkit\Support\DotEnvFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The .env editor.
 *
 * The promise this class makes is that everything you did not change comes back
 * byte for byte - comments, blank lines, ordering, export prefixes and existing
 * quoting included. Most of these tests exist to hold that promise rather than to
 * check the parsing, because parse-and-rewrite is the easy way to silently mangle
 * somebody's configuration file.
 */
final class DotEnvFileTest extends TestCase
{
    #[Test]
    public function an_untouched_file_round_trips_unchanged(): void
    {
        $raw = <<<'ENV'
        # Database
        DB_HOST=localhost
        DB_PORT=3306

        # Secrets
        export API_KEY="a b c"
        TOKEN='single quoted'
        EMPTY=
        ENV;

        $rendered = DotEnvFile::parse($raw)->merge([])->render();

        // Byte for byte, including the fact that this fixture has no trailing
        // newline. Inventing one would be a change to a file nobody asked to change.
        $this->assertSame($raw, $rendered);
    }

    #[Test]
    public function a_crlf_file_stays_crlf(): void
    {
        $raw = "# comment\r\nA=1\r\nB=2\r\n";

        $this->assertSame($raw, DotEnvFile::parse($raw)->merge([])->render());
        $this->assertSame("# comment\r\nA=9\r\nB=2\r\n", DotEnvFile::parse($raw)->merge(['A' => '9'])->render());
    }

    #[Test]
    public function trailing_blank_lines_are_kept(): void
    {
        $raw = "A=1\n\n\n";

        $this->assertSame($raw, DotEnvFile::parse($raw)->merge([])->render());
    }

    #[Test]
    public function only_the_changed_line_is_rewritten(): void
    {
        $raw = "# keep me\nA=1\nB=2  # trailing note\n";

        $rendered = DotEnvFile::parse($raw)->merge(['A' => '9'])->render();

        $this->assertStringContainsString('# keep me', $rendered);
        $this->assertStringContainsString('A=9', $rendered);
        // B was not touched, so its inline comment survives verbatim.
        $this->assertStringContainsString('B=2  # trailing note', $rendered);
    }

    #[Test]
    public function writing_the_same_value_back_changes_nothing(): void
    {
        $raw = "A=\"quoted for no reason\"\n";

        $rendered = DotEnvFile::parse($raw)->merge(['A' => 'quoted for no reason'])->render();

        $this->assertSame($raw, $rendered);
    }

    #[Test]
    public function it_reads_values_in_both_quote_styles(): void
    {
        $values = DotEnvFile::parse(<<<'ENV'
        DOUBLE="has spaces"
        SINGLE='has spaces'
        BARE=nospaces
        ENV)->all();

        $this->assertSame('has spaces', $values['DOUBLE']);
        $this->assertSame('has spaces', $values['SINGLE']);
        $this->assertSame('nospaces', $values['BARE']);
    }

    #[Test]
    public function double_quotes_process_escapes_and_single_quotes_do_not(): void
    {
        $values = DotEnvFile::parse("A=\"line\\nbreak\"\nB='line\\nbreak'")->all();

        $this->assertSame("line\nbreak", $values['A']);
        // dotenv treats single quotes as literal, and so does this.
        $this->assertSame('line\\nbreak', $values['B']);
    }

    #[Test]
    public function an_unquoted_value_ends_at_an_inline_comment(): void
    {
        $values = DotEnvFile::parse('PORT=8080 # the http port')->all();

        $this->assertSame('8080', $values['PORT']);
    }

    #[Test]
    public function a_hash_inside_quotes_is_part_of_the_value(): void
    {
        $values = DotEnvFile::parse('COLOR="#ff0000"')->all();

        $this->assertSame('#ff0000', $values['COLOR']);
    }

    #[Test]
    public function the_export_prefix_survives_an_edit(): void
    {
        $rendered = DotEnvFile::parse('export A=1')->merge(['A' => '2'])->render();

        $this->assertSame('export A=2', $rendered);
    }

    #[Test]
    public function it_adds_quotes_when_a_bare_value_would_no_longer_parse_back(): void
    {
        $rendered = DotEnvFile::parse('A=simple')->merge(['A' => 'now with spaces'])->render();

        $this->assertSame('A="now with spaces"', $rendered);
    }

    #[Test]
    public function it_keeps_single_quotes_when_the_new_value_allows_it(): void
    {
        $rendered = DotEnvFile::parse("A='old value'")->merge(['A' => 'new value'])->render();

        $this->assertSame("A='new value'", $rendered);
    }

    #[Test]
    public function a_value_containing_a_single_quote_falls_back_to_double_quotes(): void
    {
        $rendered = DotEnvFile::parse("A='old'")->merge(['A' => "it's"])->render();

        $this->assertSame("A=\"it's\"", $rendered);
    }

    #[Test]
    public function lines_that_are_not_assignments_are_left_alone(): void
    {
        $raw = "not an assignment\n1INVALID=x\nVALID=y\n";

        $values = DotEnvFile::parse($raw)->all();

        $this->assertSame(['VALID' => 'y'], $values);
        // And they still come back on render.
        $this->assertSame($raw, DotEnvFile::parse($raw)->merge([])->render());
    }

    #[Test]
    public function it_builds_an_appendable_line_quoted_the_same_way_render_would(): void
    {
        $this->assertSame('A=plain', DotEnvFile::line('A', 'plain'));
        $this->assertSame('A="two words"', DotEnvFile::line('A', 'two words'));
        $this->assertSame('A=', DotEnvFile::line('A', ''));
    }

    #[Test]
    public function an_appended_line_parses_back_to_the_value_it_was_given(): void
    {
        $value = 'p@ss w#rd "with" $signs';

        $parsed = DotEnvFile::parse(DotEnvFile::line('SECRET', $value))->all();

        $this->assertSame($value, $parsed['SECRET']);
    }

    #[Test]
    public function appending_leaves_every_existing_byte_alone(): void
    {
        $raw = "# keep\nA=1\n";

        $this->assertSame("# keep\nA=1\nB=2\n", DotEnvFile::parse($raw)->append('B', '2'));
    }

    #[Test]
    public function appending_uses_the_files_own_line_ending(): void
    {
        $raw = "A=1\r\n";

        $this->assertSame("A=1\r\nB=2\r\n", DotEnvFile::parse($raw)->append('B', '2'));
    }

    #[Test]
    public function appending_keeps_trailing_blank_lines(): void
    {
        // They are the user's blank lines. Tidying them away is still a change to a
        // part of the file nobody asked to change.
        $this->assertSame("A=1\n\n\nB=2\n", DotEnvFile::parse("A=1\n\n\n")->append('B', '2'));
    }

    #[Test]
    public function appending_to_a_file_with_no_trailing_newline_adds_one_first(): void
    {
        $this->assertSame("A=1\nB=2\n", DotEnvFile::parse('A=1')->append('B', '2'));
    }

    #[Test]
    public function appending_to_an_empty_file_does_not_start_with_a_blank_line(): void
    {
        $this->assertSame("A=1\n", DotEnvFile::parse('')->append('A', '1'));
    }
}

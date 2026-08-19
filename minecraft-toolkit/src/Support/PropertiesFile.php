<?php

namespace Catualus\MinecraftToolkit\Support;

/**
 * A java.util.Properties reader/writer that edits in place.
 *
 * Minecraft writes this file with Java's Properties.store, which escapes `=`, `:`,
 * `#`, `!` and backslashes in values - a real server.properties contains lines like
 * `level-type=minecraft\:normal`. It also stamps a dated comment header.
 *
 * Rather than reformat the whole file on save, every original line is kept verbatim
 * and only the lines whose values actually changed are rewritten. Comments, ordering,
 * blank lines and untouched escaping all survive a round trip untouched.
 */
final class PropertiesFile
{
    /** @var list<array{type: string, raw: string, key?: string, value?: string}> */
    private array $lines = [];

    /** The file's own line ending, so a CRLF file does not come back as LF. */
    private string $eol = "\n";

    /** The exact bytes after the last line of content, reproduced verbatim. */
    private string $trailer = '';

    public static function parse(string $raw): self
    {
        $file = new self();

        $file->eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";

        // Keep \r out of values on CRLF files; the line ending is re-added on render.
        $body = rtrim($raw, "\r\n");
        $file->trailer = substr($raw, strlen($body));

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '!')) {
                $file->lines[] = ['type' => 'other', 'raw' => $line];

                continue;
            }

            $split = self::splitEntry($line);

            if ($split === null) {
                $file->lines[] = ['type' => 'other', 'raw' => $line];

                continue;
            }

            [$key, $value] = $split;

            $file->lines[] = [
                'type' => 'entry',
                'raw' => $line,
                'key' => $key,
                'value' => $value,
            ];
        }

        return $file;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $values = [];

        foreach ($this->lines as $line) {
            if ($line['type'] === 'entry') {
                $values[$line['key']] = $line['value'];
            }
        }

        return $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @param  array<string, string>  $values
     */
    public function merge(array $values): self
    {
        foreach ($this->lines as $index => $line) {
            if ($line['type'] !== 'entry' || !array_key_exists($line['key'], $values)) {
                continue;
            }

            $new = (string) $values[$line['key']];

            if ($new === $line['value']) {
                continue;
            }

            $this->lines[$index]['value'] = $new;
            // Dropping raw is what marks this line for re-serialisation.
            $this->lines[$index]['raw'] = null;
        }

        return $this;
    }

    public function render(): string
    {
        $out = [];

        foreach ($this->lines as $line) {
            $out[] = $line['raw'] ?? ($line['key'] . '=' . self::escapeValue($line['value']));
        }

        return implode($this->eol, $out) . $this->trailer;
    }

    /**
     * Splits on the first unescaped `=` or `:`, which is what Java does.
     *
     * @return array{string, string}|null
     */
    private static function splitEntry(string $line): ?array
    {
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '=' || $char === ':') {
                return [
                    self::unescape(trim(substr($line, 0, $i))),
                    self::unescape(ltrim(substr($line, $i + 1))),
                ];
            }
        }

        return null;
    }

    private static function unescape(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $value[$i];

                continue;
            }

            $next = $value[++$i];

            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'f' => "\f",
                'u' => self::unescapeUnicode($value, $i),
                default => $next,
            };

            if ($next === 'u') {
                $i += 4;
            }
        }

        return $out;
    }

    private static function unescapeUnicode(string $value, int $at): string
    {
        $hex = substr($value, $at + 1, 4);

        if (strlen($hex) < 4 || !ctype_xdigit($hex)) {
            return 'u';
        }

        return mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
    }

    /**
     * Mirrors Java's saveConvert for values: escape the separators and comment
     * markers so the file Minecraft reads back means what we wrote.
     */
    private static function escapeValue(string $value): string
    {
        $out = '';

        foreach (str_split($value) as $char) {
            $out .= match ($char) {
                '\\' => '\\\\',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\f" => '\\f',
                '=', ':', '#', '!' => '\\' . $char,
                default => $char,
            };
        }

        return $out;
    }
}

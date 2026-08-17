<?php

namespace Catualus\AppToolkit\Support;

/**
 * A .env reader/writer that edits in place.
 *
 * Same approach as a properties file: every original line is kept verbatim and only
 * the lines whose values actually changed are rewritten, so comments, ordering, blank
 * lines, `export` prefixes and existing quoting all survive a round trip untouched.
 */
final class DotEnvFile
{
    /** @var list<array{type: string, raw: ?string, key?: string, value?: string, export?: bool, quote?: string}> */
    private array $lines = [];

    public static function parse(string $raw): self
    {
        $file = new self();

        foreach (preg_split('/\R/', rtrim($raw, "\r\n")) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $file->lines[] = ['type' => 'other', 'raw' => $line];

                continue;
            }

            $body = $trimmed;
            $export = false;

            if (str_starts_with($body, 'export ')) {
                $body = ltrim(substr($body, 7));
                $export = true;
            }

            $at = strpos($body, '=');

            if ($at === false) {
                $file->lines[] = ['type' => 'other', 'raw' => $line];

                continue;
            }

            $key = rtrim(substr($body, 0, $at));
            $rest = ltrim(substr($body, $at + 1));

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                $file->lines[] = ['type' => 'other', 'raw' => $line];

                continue;
            }

            [$value, $quote] = self::readValue($rest);

            $file->lines[] = [
                'type' => 'entry',
                'raw' => $line,
                'key' => $key,
                'value' => $value,
                'export' => $export,
                'quote' => $quote,
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
            // Dropping raw marks the line for re-serialisation.
            $this->lines[$index]['raw'] = null;
        }

        return $this;
    }

    public function render(): string
    {
        $out = [];

        foreach ($this->lines as $line) {
            if ($line['raw'] !== null) {
                $out[] = $line['raw'];

                continue;
            }

            $out[] = ($line['export'] ? 'export ' : '')
                . $line['key'] . '=' . self::quote($line['value'], $line['quote']);
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @return array{string, string}  the value and which quote style held it
     */
    private static function readValue(string $rest): array
    {
        if ($rest === '') {
            return ['', ''];
        }

        $first = $rest[0];

        if ($first === '"' || $first === "'") {
            $end = self::closingQuote($rest, $first);

            if ($end !== null) {
                $inner = substr($rest, 1, $end - 1);

                // Only double quotes process escapes, same as dotenv itself.
                return [$first === '"' ? self::unescape($inner) : $inner, $first];
            }
        }

        // Unquoted values end at an inline comment.
        $hash = strpos($rest, ' #');

        return [rtrim($hash === false ? $rest : substr($rest, 0, $hash)), ''];
    }

    private static function closingQuote(string $rest, string $quote): ?int
    {
        $length = strlen($rest);

        for ($i = 1; $i < $length; $i++) {
            if ($rest[$i] === '\\' && $quote === '"') {
                $i++;

                continue;
            }

            if ($rest[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private static function unescape(string $value): string
    {
        return str_replace(
            ['\\n', '\\r', '\\t', '\\"', '\\\\'],
            ["\n", "\r", "\t", '"', '\\'],
            $value,
        );
    }

    /**
     * Keeps the original quote style where there was one, and adds double quotes
     * when a bare value would no longer parse back to itself.
     */
    private static function quote(string $value, string $existing): string
    {
        $needsQuotes = $value === ''
            ? false
            : (bool) preg_match('/[\s#"\'\\\\$]/', $value);

        if ($existing === "'" && !str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        if ($existing === '' && !$needsQuotes) {
            return $value;
        }

        return '"' . str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value) . '"';
    }
}

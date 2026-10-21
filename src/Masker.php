<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

/**
 * Hides secrets before anything is shown. Keys are matched by name, case-insensitively,
 * against the configured words; the value is replaced, the key stays visible.
 */
final class Masker
{
    public const string MASK = '••••••';

    /** @var list<string> */
    private array $words;

    /**
     * @param  list<string>  $words
     * @param  list<string>  $headers  exact header names to mask as well (signature headers)
     * @param  list<string>  $secrets  values hidden wherever they appear in free text (signing secrets)
     */
    public function __construct(array $words, private readonly array $headers = [], private readonly array $secrets = [])
    {
        $this->words = array_values(array_filter(array_map(
            fn (string $w): string => strtolower(trim($w)),
            $words,
        ), fn (string $w): bool => $w !== ''));
    }

    /**
     * The secret values stored with one call: scalars under secret payload keys, secret headers and
     * secret URL parameters. Free text (exception messages, traces) is masked against these.
     *
     * @param  array<array-key, mixed>|null  $payload
     * @param  array<array-key, mixed>|null  $headers
     * @return list<string>
     */
    public function secretsOf(?array $payload, ?array $headers, string $url = ''): array
    {
        $found = [];

        $walk = function (mixed $data, bool $secret) use (&$walk, &$found): void {
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $walk($value, $secret || $this->isSecret($key));
                }
            } elseif ($secret && is_scalar($data)) {
                $found[] = (string) $data;
            }
        };

        $walk($payload ?? [], false);

        $signature = array_map('strtolower', $this->headers);
        foreach ($headers ?? [] as $name => $values) {
            $walk($values, $this->isSecret($name) || in_array(strtolower((string) $name), $signature, true));
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $walk($query, false);

        return array_values(array_unique(array_filter($found, fn (string $v): bool => strlen($v) >= 4)));
    }

    /**
     * Masks free text such as an exception message or stack trace: every known secret value, any
     * "secret_key=value", "secret_key: value" or "\"secret_key\":\"value\"" pair, and bearer tokens.
     *
     * @param  list<string>  $secrets  extra values to hide, usually secretsOf() for the call
     */
    public function text(string $text, array $secrets = []): string
    {
        $values = array_values(array_unique(array_filter([...$this->secrets, ...$secrets], fn (string $v): bool => strlen($v) >= 4)));
        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a)); // longest first

        if ($values !== []) {
            $text = str_replace($values, self::MASK, $text);
        }

        // Bearer/Basic credentials first, so "Authorization: Bearer x" keeps its scheme and hides x.
        $text = (string) preg_replace('/\b(Bearer|Basic|Token)\s+(?!'.preg_quote(self::MASK, '/').')[A-Za-z0-9._~+\/=-]{4,}/i', '$1 '.self::MASK, $text);

        if ($this->words !== []) {
            $words = implode('|', array_map(fn (string $w): string => preg_quote($w, '/'), $this->words));
            $text = (string) preg_replace(
                '/((?:[\w.-]*(?:'.$words.')[\w.-]*)["\']?\s*[:=]\s*["\']?(?:(?:Bearer|Basic|Token)\s+)?+)(?!'.preg_quote(self::MASK, '/').')[^\s"\'&,;}\]]+/iu',
                '$1'.self::MASK,
                $text,
            );
        }

        return $text;
    }

    public function isSecret(string|int $key): bool
    {
        $key = strtolower((string) $key);

        foreach ($this->words as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public function payload(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        // Rows from webhook-client < 3.7 kept uploaded files (base64) inside the payload.
        if (isset($data['originalName'], $data['content']) && is_string($data['content'])) {
            $data['content'] = '('.strlen($data['content']).' base64 characters hidden)';
        }

        foreach ($data as $key => $value) {
            if ($this->isSecret($key) && $value !== null && $value !== '' && ! is_array($value)) {
                $data[$key] = self::MASK;
            } elseif ($this->isSecret($key) && is_array($value)) {
                $data[$key] = array_map(fn (): string => self::MASK, $value);
            } elseif (is_array($value)) {
                $data[$key] = $this->payload($value);
            }
        }

        return $data;
    }

    /**
     * Headers as webhook-client stores them: lower-case name => list of values.
     *
     * @param  array<array-key, mixed>|null  $headers
     * @return array<string, list<string>>
     */
    public function headers(?array $headers): array
    {
        $signature = array_map('strtolower', $this->headers);
        $out = [];

        foreach ($headers ?? [] as $name => $values) {
            $values = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : (string) json_encode($v), (array) $values);
            $secret = $this->isSecret($name) || in_array(strtolower((string) $name), $signature, true);
            $out[(string) $name] = $secret ? array_map(fn (): string => self::MASK, $values) : array_values($values);
        }

        ksort($out);

        return $out;
    }

    public function url(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        $query = [];

        foreach (explode('&', $parts['query']) as $pair) {
            [$key] = explode('=', $pair, 2);
            $query[] = $this->isSecret(urldecode($key)) && str_contains($pair, '=') ? $key.'='.self::MASK : $pair;
        }

        return str_replace('?'.$parts['query'], '?'.implode('&', $query), $url);
    }

    /**
     * Attachment metadata only: the stored base64 content and temp path are never shown.
     *
     * @param  array<array-key, mixed>|null  $attachments
     * @return list<array{name: string, type: string, size: int}>
     */
    public function attachments(?array $attachments): array
    {
        $out = [];

        foreach ($attachments ?? [] as $file) {
            if (is_array($file)) {
                $out[] = [
                    'name' => (string) ($file['originalName'] ?? 'file'),
                    'type' => (string) ($file['mimeType'] ?? ''),
                    'size' => (int) ($file['size'] ?? 0),
                ];
            }
        }

        return $out;
    }
}

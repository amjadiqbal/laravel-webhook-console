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
     */
    public function __construct(array $words, private readonly array $headers = [])
    {
        $this->words = array_values(array_filter(array_map(
            fn (string $w): string => strtolower(trim($w)),
            $words,
        ), fn (string $w): bool => $w !== ''));
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

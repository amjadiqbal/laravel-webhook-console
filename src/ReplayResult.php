<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Spatie\WebhookClient\Models\WebhookCall;

final readonly class ReplayResult
{
    public const string DISPATCHED = 'dispatched';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    private function __construct(
        public WebhookCall $call,
        public string $outcome,
        public string $message,
    ) {}

    public static function dispatched(WebhookCall $call, string $job): self
    {
        return new self($call, self::DISPATCHED, "{$job} dispatched");
    }

    public static function failed(WebhookCall $call, string $message): self
    {
        return new self($call, self::FAILED, $message);
    }

    public static function skipped(WebhookCall $call, string $reason): self
    {
        return new self($call, self::SKIPPED, $reason);
    }

    public function ok(): bool
    {
        return $this->outcome === self::DISPATCHED;
    }

    /**
     * @param  list<self>  $results
     * @return array{dispatched: int, failed: int, skipped: int}
     */
    public static function tally(array $results): array
    {
        $tally = [self::DISPATCHED => 0, self::FAILED => 0, self::SKIPPED => 0];

        foreach ($results as $result) {
            $tally[$result->outcome]++;
        }

        return $tally;
    }
}

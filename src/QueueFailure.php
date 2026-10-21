<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Carbon\CarbonImmutable;

/** A failed_jobs row whose job was a webhook-client process job for a known call. */
final readonly class QueueFailure
{
    public function __construct(
        public string $id,
        public string $jobClass,
        public string $connection,
        public string $queue,
        public string $exception,
        public CarbonImmutable $failedAt,
    ) {}

    /** The first line of the stored exception, which holds its class and message. */
    public function message(): string
    {
        return trim(strtok($this->exception, "\n") ?: $this->exception);
    }
}

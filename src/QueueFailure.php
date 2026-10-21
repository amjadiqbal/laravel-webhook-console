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

    /** The exception class and message: the first line of the stored text, without its " in /file.php:12". */
    public function message(): string
    {
        $line = trim(strtok($this->exception, "\n") ?: $this->exception);

        return (string) preg_replace('/ in \S+:\d+$/', '', $line);
    }
}

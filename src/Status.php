<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

enum Status: string
{
    /** webhook-client stored an exception on the call (dispatch failed, or the job threw on the sync queue). */
    case Failed = 'failed';

    /** The process job failed on a queue worker and is in failed_jobs. */
    case QueueFailed = 'queue_failed';

    /** No error is recorded for the call. */
    case Ok = 'ok';

    public function label(): string
    {
        return match ($this) {
            self::Failed => 'Failed',
            self::QueueFailed => 'Failed in queue',
            self::Ok => 'No error',
        };
    }

    public function isFailure(): bool
    {
        return $this !== self::Ok;
    }
}

<?php

namespace AmjadIqbal\WebhookConsole\Tests\Fixtures;

use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ThrowingJob extends ProcessWebhookJob
{
    public static bool $fail = true;

    public function handle(): void
    {
        if (self::$fail) {
            throw new RuntimeException('Upstream API timed out for '.($this->webhookCall->payload['type'] ?? 'event'));
        }

        RecordingJob::$handled[] = $this->webhookCall->getKey();
    }
}

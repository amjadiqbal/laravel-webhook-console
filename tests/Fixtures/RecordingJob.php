<?php

namespace AmjadIqbal\WebhookConsole\Tests\Fixtures;

use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class RecordingJob extends ProcessWebhookJob
{
    /** @var list<int|string> */
    public static array $handled = [];

    public function handle(): void
    {
        self::$handled[] = $this->webhookCall->getKey();
    }
}

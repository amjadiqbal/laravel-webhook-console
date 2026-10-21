<?php

namespace AmjadIqbal\WebhookConsole\Tests\Fixtures;

use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class CustomCallJob extends ProcessWebhookJob
{
    /** @var list<string> */
    public static array $classes = [];

    public function handle(): void
    {
        self::$classes[] = $this->webhookCall::class;
    }
}

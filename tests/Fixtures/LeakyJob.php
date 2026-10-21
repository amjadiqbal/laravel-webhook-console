<?php

namespace AmjadIqbal\WebhookConsole\Tests\Fixtures;

use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

/** Throws with the payload's API key in the message, as a careless client library would. */
class LeakyJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        throw new RuntimeException('Gateway refused key '.($this->webhookCall->payload['api_key'] ?? '').' (Authorization: Bearer tok_LEAKY_BEARER)');
    }
}

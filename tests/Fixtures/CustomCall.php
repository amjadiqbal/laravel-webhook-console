<?php

namespace AmjadIqbal\WebhookConsole\Tests\Fixtures;

use Spatie\WebhookClient\Models\WebhookCall;

/** A custom webhook_model that keeps the default table. */
class CustomCall extends WebhookCall
{
    protected $table = 'webhook_calls';
}

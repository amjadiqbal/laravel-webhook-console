<?php

namespace AmjadIqbal\WebhookConsole\Tests\Fixtures;

use Spatie\WebhookClient\Models\WebhookCall;

/** A custom webhook_model with its own table. */
class OtherTableCall extends WebhookCall
{
    protected $table = 'custom_webhook_calls';
}

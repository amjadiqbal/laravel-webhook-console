<?php

use AmjadIqbal\WebhookConsole\Tests\Fixtures\CustomCall;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\CustomCallJob;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\OtherTableCall;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\RecordingJob;
use AmjadIqbal\WebhookConsole\Tests\TestCase;
use AmjadIqbal\WebhookConsole\WebhookConsole;
use Spatie\WebhookClient\Models\WebhookCall;

beforeEach(function () {
    RecordingJob::$handled = [];
    CustomCallJob::$classes = [];
});

it('replays a call through its config\'s custom webhook_model', function () {
    config()->set('webhook-client.configs', [TestCase::endpoint('default', CustomCallJob::class, CustomCall::class)]);
    app()->forgetScopedInstances();
    $this->asOperator();

    $this->sendWebhook(['type' => 'custom'])->assertOk();
    expect(CustomCallJob::$classes)->toBe([CustomCall::class]);

    $call = WebhookCall::sole();
    $this->post("/webhook-console/calls/{$call->id}/replay")->assertSessionHas('webhook-console.flash.type', 'ok');

    expect(CustomCallJob::$classes)->toBe([CustomCall::class, CustomCall::class]);
});

it('shows each table as its own source when models use different tables', function () {
    config()->set('webhook-client.configs', [
        TestCase::endpoint('default', RecordingJob::class),
        TestCase::endpoint('other', CustomCallJob::class, OtherTableCall::class),
    ]);
    app()->forgetScopedInstances();
    $this->asOperator();

    $this->sendWebhook(['type' => 'in.default'], 'default')->assertOk();
    $this->sendWebhook(['type' => 'in.other'], 'other')->assertOk();

    expect(array_keys(app(WebhookConsole::class)->sources()))->toBe(['webhook_calls', 'custom_webhook_calls'])
        ->and(OtherTableCall::count())->toBe(1);

    $this->get('/webhook-console')->assertSee('in.default')->assertDontSee('in.other');
    $this->get('/webhook-console?source=custom_webhook_calls')->assertSee('in.other')->assertDontSee('in.default')->assertSee('custom_webhook_calls');

    $id = OtherTableCall::sole()->id;
    $this->post("/webhook-console/calls/{$id}/replay", ['source' => 'custom_webhook_calls'])->assertSessionHas('webhook-console.flash.type', 'ok');

    expect(CustomCallJob::$classes)->toBe([OtherTableCall::class, OtherTableCall::class]);
});

it('works with the published default config, whose process_webhook_job is empty', function () {
    config()->set('webhook-client.configs', [[...TestCase::endpoint('default', RecordingJob::class), 'process_webhook_job' => '']]);
    app()->forgetScopedInstances();
    $this->asOperator();
    $call = WebhookCall::create(['name' => 'default', 'url' => 'u', 'payload' => ['type' => 'x']]);

    $this->get("/webhook-console/calls/{$call->id}")->assertOk()->assertSee('has no process_webhook_job');
    $this->post("/webhook-console/calls/{$call->id}/replay")->assertSessionHas('webhook-console.flash.type', 'error');
});

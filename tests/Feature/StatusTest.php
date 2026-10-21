<?php

use AmjadIqbal\WebhookConsole\FailedJobs;
use AmjadIqbal\WebhookConsole\Status;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\RecordingJob;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\ThrowingJob;
use AmjadIqbal\WebhookConsole\Tests\TestCase;
use AmjadIqbal\WebhookConsole\WebhookConsole;
use Spatie\WebhookClient\Models\WebhookCall;

beforeEach(function () {
    RecordingJob::$handled = [];
    ThrowingJob::$fail = true;
});

function useThrowingJob(): void
{
    config()->set('webhook-client.configs', [
        TestCase::endpoint('default', RecordingJob::class),
        TestCase::endpoint('other', ThrowingJob::class),
    ]);
    app()->forgetScopedInstances();
}

it('stores a signed webhook through webhook-client and processes it', function () {
    $this->sendWebhook(['type' => 'invoice.paid', 'id' => 'evt_1'])->assertOk();

    $call = WebhookCall::sole();
    $console = app(WebhookConsole::class);

    expect(RecordingJob::$handled)->toBe([$call->id])
        ->and($console->status($call))->toBe(Status::Ok)
        ->and($console->event($call))->toBe('invoice.paid');
});

it('reports the stored exception when the job throws on the sync queue', function () {
    useThrowingJob();

    $this->withoutExceptionHandling();

    try {
        $this->sendWebhook(['type' => 'charge.failed'], 'other');
    } catch (RuntimeException) {
    }

    $call = WebhookCall::sole();

    expect($call->exception['message'])->toBe('Upstream API timed out for charge.failed')
        ->and(app(WebhookConsole::class)->status($call))->toBe(Status::Failed);
});

it('links a job that failed on a queue worker to its call through failed_jobs', function () {
    useThrowingJob();
    config()->set('queue.default', 'database');

    $this->sendWebhook(['type' => 'push'], 'other')->assertOk();
    $this->sendWebhook(['type' => 'ok'], 'default')->assertOk();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

    [$failed, $fine] = WebhookCall::orderBy('id')->get()->all();
    $console = app(WebhookConsole::class);

    expect($failed->exception)->toBeNull() // webhook-client itself never sees this failure
        ->and($console->status($failed))->toBe(Status::QueueFailed)
        ->and($console->activeQueueFailure($failed)?->message())->toBe('RuntimeException: Upstream API timed out for push')
        ->and($console->status($fine))->toBe(Status::Ok)
        ->and($console->activeQueueFailureIds('webhook_calls'))->toBe([(string) $failed->id]);
});

it('parses the call id from a failed job payload without unserializing it', function () {
    $command = serialize(new ThrowingJob(tap(new WebhookCall, fn ($c) => $c->forceFill(['id' => 42])->exists = true)));
    $payload = json_encode(['displayName' => ThrowingJob::class, 'data' => ['commandName' => ThrowingJob::class, 'command' => $command]]);

    expect(app(FailedJobs::class)->parse((string) $payload))->toBe(['job' => ThrowingJob::class, 'model' => WebhookCall::class, 'id' => '42'])
        ->and(app(FailedJobs::class)->parse((string) json_encode(['displayName' => 'App\\Jobs\\Other', 'data' => ['command' => $command]])))->toBeNull()
        ->and(app(FailedJobs::class)->parse('not json'))->toBeNull();
});

it('reads the event name from provider headers before the payload', function () {
    $console = app(WebhookConsole::class);
    $github = new WebhookCall(['headers' => ['x-github-event' => ['pull_request']], 'payload' => ['action' => 'opened']]);
    $push = new WebhookCall(['headers' => ['x-github-event' => ['push']], 'payload' => ['ref' => 'refs/heads/main']]);
    $paddle = new WebhookCall(['headers' => [], 'payload' => ['event_type' => 'transaction.completed']]);

    expect($console->event($github))->toBe('pull_request.opened')
        ->and($console->event($push))->toBe('push')
        ->and($console->event($paddle))->toBe('transaction.completed')
        ->and($console->event(new WebhookCall(['payload' => ['id' => 1]])))->toBeNull();
});

it('says why failed_jobs cannot be read', function () {
    config()->set('queue.failed.driver', 'dynamodb');

    expect(app(FailedJobs::class)->unavailableReason())->toContain("'dynamodb'");
});

<?php

use AmjadIqbal\WebhookConsole\Tests\Fixtures\RecordingJob;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\ThrowingJob;
use AmjadIqbal\WebhookConsole\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Spatie\WebhookClient\Models\WebhookCall;

beforeEach(function () {
    RecordingJob::$handled = [];
    ThrowingJob::$fail = true;
});

function failedCall(string $message = 'Boom', array $attributes = []): WebhookCall
{
    return WebhookCall::create([
        'name' => 'default', 'url' => 'https://app.test/webhooks/default', 'payload' => ['type' => 'invoice.paid'],
        'exception' => ['code' => 0, 'message' => $message, 'trace' => ''], ...$attributes,
    ]);
}

it('lists failed calls and passes without --strict', function () {
    failedCall('Stripe said no');
    WebhookCall::create(['name' => 'default', 'url' => 'u', 'payload' => ['type' => 'fine']]);

    $this->artisan('webhooks:failed')
        ->expectsOutputToContain('Stripe said no')
        ->doesntExpectOutputToContain('fine')
        ->expectsOutputToContain('1 failed call')
        ->assertSuccessful();
});

it('fails with --strict when there are failures, and passes when there are none', function () {
    $this->artisan('webhooks:failed --strict')->expectsOutputToContain('No failed webhook calls')->assertSuccessful();

    failedCall();

    $this->artisan('webhooks:failed --strict')->assertFailed();
});

it('filters by --since and --name, and prints JSON', function () {
    $old = failedCall('old one');
    $old->forceFill(['created_at' => now()->subDays(3)])->save();
    failedCall('new one', ['name' => 'github']);

    $this->artisan('webhooks:failed --since=1d')->expectsOutputToContain('new one')->doesntExpectOutputToContain('old one')->assertSuccessful();
    $this->artisan('webhooks:failed --name=default')->expectsOutputToContain('old one')->doesntExpectOutputToContain('new one')->assertSuccessful();
    $this->artisan('webhooks:failed --since=yesterdayish')->assertExitCode(2);

    expect(Artisan::call('webhooks:failed', ['--json' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true);
    expect($json['failed_jobs_read'])->toBeTrue()
        ->and(array_column($json['calls'], 'error'))->toBe(['new one', 'old one'])
        ->and(array_column($json['calls'], 'status'))->toBe(['failed', 'failed']);
});

it('needs IDs or --failed', function () {
    $this->artisan('webhooks:replay')->assertExitCode(2);
});

it('replays calls by ID after confirmation', function () {
    $a = failedCall();
    $b = failedCall();

    $this->artisan("webhooks:replay {$b->id} 999")
        ->expectsOutputToContain('No webhook call #999')
        ->expectsConfirmation('Dispatch the process job again for 1 call?', 'yes')
        ->assertSuccessful();

    expect(RecordingJob::$handled)->toBe([$b->id])->and($a->fresh()->exception)->not->toBeNull();
});

it('does nothing when not confirmed or on a dry run', function () {
    failedCall();

    $this->artisan('webhooks:replay --failed')->expectsConfirmation('Dispatch the process job again for 1 call?', 'no')->assertFailed();
    $this->artisan('webhooks:replay --failed --dry-run')->expectsOutputToContain('Dry run: 1 call would be replayed')->assertSuccessful();

    expect(RecordingJob::$handled)->toBe([]);
});

it('replays every failed call with --failed --force, oldest first, honouring --limit', function () {
    $a = failedCall();
    $b = failedCall();
    $c = failedCall();

    $this->artisan('webhooks:replay --failed --force --limit=2')->expectsOutputToContain('2 replayed, 0 failed to dispatch, 0 skipped')->assertSuccessful();
    expect(RecordingJob::$handled)->toBe([$b->id, $c->id]);

    $this->artisan('webhooks:replay --failed --force')->assertSuccessful();
    expect(RecordingJob::$handled)->toBe([$b->id, $c->id, $a->id]);

    $this->artisan('webhooks:replay --failed --force')->expectsOutputToContain('Nothing to replay')->assertSuccessful();
});

it('exits 1 when a replay throws again', function () {
    config()->set('webhook-client.configs', [TestCase::endpoint('default', ThrowingJob::class)]);
    app()->forgetScopedInstances();
    $call = failedCall();

    $this->artisan("webhooks:replay {$call->id} --force")->expectsOutputToContain('Upstream API timed out')->assertFailed();
});

it('adds itself to artisan about', function () {
    $this->artisan('about')->expectsOutputToContain('Webhook Console')->assertSuccessful();
});

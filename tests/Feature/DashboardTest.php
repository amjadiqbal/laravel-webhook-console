<?php

use AmjadIqbal\WebhookConsole\Tests\Fixtures\RecordingJob;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\ThrowingJob;
use AmjadIqbal\WebhookConsole\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Spatie\WebhookClient\Models\WebhookCall;

beforeEach(function () {
    RecordingJob::$handled = [];
    ThrowingJob::$fail = true;
});

function storeCall(array $attributes = []): WebhookCall
{
    return WebhookCall::create([
        'name' => 'default',
        'url' => 'https://app.test/webhooks/default',
        'headers' => ['content-type' => ['application/json']],
        'payload' => ['type' => 'invoice.paid'],
        'exception' => null,
        ...$attributes,
    ]);
}

it('denies the dashboard outside the local environment by default', function () {
    storeCall();

    $this->get('/webhook-console')->assertForbidden();
    $this->get('/webhook-console/calls/1')->assertForbidden();
});

it('lists calls with their config, event and status locally', function () {
    $this->asLocal();
    storeCall(['payload' => ['type' => 'invoice.paid']]);
    storeCall(['name' => 'github', 'payload' => ['action' => 'opened'], 'exception' => ['code' => 0, 'message' => 'Boom from GitHub', 'trace' => '#0']]);

    $this->get('/webhook-console')
        ->assertOk()
        ->assertSee('invoice.paid')
        ->assertSee('opened')
        ->assertSee('Boom from GitHub')
        ->assertSee('Failed (stored exception)')
        ->assertSee('Replay selected');
});

it('filters by config name, status, date and payload search', function () {
    $this->asLocal();
    storeCall(['payload' => ['type' => 'invoice.paid', 'id' => 'evt_AAA']]);
    storeCall(['name' => 'github', 'payload' => ['action' => 'closed', 'id' => 'evt_BBB'], 'exception' => ['code' => 0, 'message' => 'x', 'trace' => '']]);
    $old = storeCall(['payload' => ['type' => 'very.old', 'id' => 'evt_CCC']]);
    $old->forceFill(['created_at' => now()->subDays(40)])->save();

    $this->get('/webhook-console?name=github')->assertSee('closed')->assertDontSee('invoice.paid');
    $this->get('/webhook-console?status=failed')->assertSee('closed')->assertDontSee('invoice.paid');
    $this->get('/webhook-console?status=ok')->assertSee('invoice.paid')->assertDontSee('>closed<', false);
    $this->get('/webhook-console?q=evt_AAA')->assertSee('invoice.paid')->assertDontSee('very.old');
    $this->get('/webhook-console?from='.now()->subDays(2)->format('Y-m-d'))->assertSee('invoice.paid')->assertDontSee('very.old');
});

it('shows payload and headers with secrets masked', function () {
    $this->asLocal();
    $call = storeCall([
        'url' => 'https://app.test/webhooks/default?token=url-secret-value',
        'headers' => ['signature' => ['sig-secret-value'], 'authorization' => ['Bearer auth-secret-value'], 'user-agent' => ['Stripe/1.0']],
        'payload' => ['type' => 'customer.updated', 'data' => ['password' => 'payload-secret-value', 'email' => 'a@b.test']],
    ]);

    $this->get("/webhook-console/calls/{$call->id}")
        ->assertOk()
        ->assertSee('customer.updated')
        ->assertSee('a@b.test')
        ->assertSee('Stripe/1.0')
        ->assertDontSee('payload-secret-value')
        ->assertDontSee('sig-secret-value')
        ->assertDontSee('auth-secret-value')
        ->assertDontSee('url-secret-value')
        ->assertSee(RecordingJob::class);
});

it('returns 404 for an unknown call and when the dashboard is off', function () {
    $this->asLocal();

    $this->get('/webhook-console/calls/999')->assertNotFound();
});

it('replays one call by dispatching its configured job again', function () {
    $this->asLocal();
    $call = storeCall(['exception' => ['code' => 0, 'message' => 'old failure', 'trace' => '']]);

    $this->from('/webhook-console/calls/'.$call->id)
        ->post("/webhook-console/calls/{$call->id}/replay")
        ->assertRedirect('/webhook-console/calls/'.$call->id)
        ->assertSessionHas('webhook-console.flash.type', 'ok');

    expect(RecordingJob::$handled)->toBe([$call->id])
        ->and($call->fresh()->exception)->toBeNull();
});

it('stores the exception again when a replay fails on the sync queue', function () {
    $this->asLocal();
    config()->set('webhook-client.configs', [TestCase::endpoint('default', ThrowingJob::class)]);
    app()->forgetScopedInstances();
    $call = storeCall();

    $this->post("/webhook-console/calls/{$call->id}/replay")
        ->assertSessionHas('webhook-console.flash.type', 'error');

    expect($call->fresh()->exception['message'])->toBe('Upstream API timed out for invoice.paid');
});

it('refuses to replay a call whose config is gone or has no job', function () {
    $this->asLocal();
    $orphan = storeCall(['name' => 'removed']);

    $this->post("/webhook-console/calls/{$orphan->id}/replay")
        ->assertSessionHas('webhook-console.flash.message', "Call #{$orphan->id} was not replayed: No webhook-client config is named 'removed' any more.");

    $this->get("/webhook-console/calls/{$orphan->id}")->assertSee('not in config/webhook-client.php any more');
});

it('bulk-retries every failure matching the filters, oldest first', function () {
    $this->asLocal();
    $a = storeCall(['exception' => ['code' => 0, 'message' => 'a', 'trace' => '']]);
    storeCall();
    $c = storeCall(['exception' => ['code' => 0, 'message' => 'c', 'trace' => '']]);

    $this->post('/webhook-console/retry', ['all' => '1'])
        ->assertSessionHas('webhook-console.flash.message', '2 replayed, 0 failed to dispatch, 0 skipped.');

    expect(RecordingJob::$handled)->toBe([$a->id, $c->id]);
});

it('replays only the selected calls', function () {
    $this->asLocal();
    storeCall();
    $b = storeCall();

    $this->post('/webhook-console/retry', ['ids' => [(string) $b->id]])
        ->assertSessionHas('webhook-console.flash.message', '1 replayed, 0 failed to dispatch, 0 skipped.');

    expect(RecordingJob::$handled)->toBe([$b->id]);
});

it('prunes calls older than the given days', function () {
    $this->asLocal();
    $old = storeCall();
    $old->forceFill(['created_at' => now()->subDays(10)])->save();
    $oldOther = storeCall(['name' => 'github']);
    $oldOther->forceFill(['created_at' => now()->subDays(10)])->save();
    storeCall();

    $this->post('/webhook-console/prune', ['days' => 5, 'name' => 'default'])
        ->assertSessionHas('webhook-console.flash.message', "Deleted 1 call older than 5 days for 'default'.");

    expect(WebhookCall::count())->toBe(2);

    $this->post('/webhook-console/prune', ['days' => 0])->assertSessionHasErrors('days');
});

it('keeps replay and prune behind their own gates', function () {
    $this->asLocal();
    Gate::define('replayWebhookCalls', fn ($user = null) => false);
    Gate::define('pruneWebhookCalls', fn ($user = null) => false);
    $call = storeCall(['exception' => ['code' => 0, 'message' => 'x', 'trace' => '']]);

    $this->get('/webhook-console')->assertOk()->assertDontSee('Replay selected')->assertDontSee('Prune old calls')->assertSee('read-only');
    $this->get("/webhook-console/calls/{$call->id}")->assertOk()->assertDontSee('>Replay</button>', false);
    $this->post("/webhook-console/calls/{$call->id}/replay")->assertForbidden();
    $this->post('/webhook-console/retry', ['all' => '1'])->assertForbidden();
    $this->post('/webhook-console/prune', ['days' => 1])->assertForbidden();

    expect(RecordingJob::$handled)->toBe([])->and(WebhookCall::count())->toBe(1);
});

it('lets the app open the dashboard with its own gate', function () {
    Gate::define('viewWebhookConsole', fn ($user = null) => request()->header('X-Ops') === 'yes');

    $this->get('/webhook-console')->assertForbidden();
    $this->get('/webhook-console', ['X-Ops' => 'yes'])->assertOk();
});

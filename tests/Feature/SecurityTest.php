<?php

use AmjadIqbal\WebhookConsole\Masker;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\RecordingJob;
use AmjadIqbal\WebhookConsole\Tests\Fixtures\ThrowingJob;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\WebhookClient\Models\WebhookCall;

beforeEach(function () {
    RecordingJob::$handled = [];
});

function secretCall(array $attributes = []): WebhookCall
{
    return WebhookCall::create([
        'name' => 'default',
        'url' => 'https://app.test/webhooks/default?token=tok_URLSECRET',
        'headers' => ['authorization' => ['Bearer hdr_HEADERSECRET'], 'content-type' => ['application/json']],
        'payload' => ['type' => 'charge.failed', 'api_key' => 'sk_live_PAYLOADSECRET', 'customer' => 'cus_visible'],
        'exception' => [
            'code' => 0,
            'message' => 'Stripe rejected sk_live_PAYLOADSECRET for cus_visible (password=pw_INLINESECRET)',
            'trace' => "#0 Client->call('sk_live_PAYLOADSECRET', 'Bearer abc_BEARERSECRET')\n#1 signing secret was secret",
        ],
        ...$attributes,
    ]);
}

it('is read-only by default, even locally: replay and prune are denied until the app defines their gates', function () {
    $this->asLocal(); // a guest, no gates defined
    $call = secretCall();

    $this->get('/webhook-console')->assertOk()->assertSee('read-only')->assertDontSee('Retry failed')->assertDontSee('Prune old calls');
    $this->get("/webhook-console/calls/{$call->id}")->assertOk()->assertDontSee('>Replay</button>', false);
    $this->post("/webhook-console/calls/{$call->id}/replay")->assertForbidden();
    $this->post('/webhook-console/retry', ['all' => '1'])->assertForbidden();
    $this->post('/webhook-console/prune', ['days' => 1])->assertForbidden();

    // Signed in, still no gate: still denied.
    $this->actingAs(new GenericUser(['id' => 1]));
    $this->post("/webhook-console/calls/{$call->id}/replay")->assertForbidden();

    expect(RecordingJob::$handled)->toBe([])->and(WebhookCall::count())->toBe(1);
});

it('requires a signed-in user for replay and prune even when the gate allows guests', function () {
    $this->asLocal();
    Gate::define('replayWebhookCalls', fn ($user = null) => true);
    Gate::define('pruneWebhookCalls', fn ($user = null) => true);
    $call = secretCall();

    $this->post("/webhook-console/calls/{$call->id}/replay")->assertForbidden();
    $this->post('/webhook-console/prune', ['days' => 1])->assertForbidden();

    config()->set('webhook-console.dashboard.actions_require_user', false); // an app without authentication

    $this->post("/webhook-console/calls/{$call->id}/replay")->assertSessionHas('webhook-console.flash.type', 'ok');
    expect(RecordingJob::$handled)->toBe([$call->id]);
});

it('masks secrets inside stored exception messages and stack traces', function () {
    $this->asLocal();
    $call = secretCall();

    $page = $this->get("/webhook-console/calls/{$call->id}")->assertOk()->assertSee('cus_visible');
    foreach (['sk_live_PAYLOADSECRET', 'pw_INLINESECRET', 'abc_BEARERSECRET', 'tok_URLSECRET', 'hdr_HEADERSECRET'] as $secret) {
        $page->assertDontSee($secret);
    }

    $this->get('/webhook-console')->assertOk()->assertSee('Stripe rejected')->assertDontSee('sk_live_PAYLOADSECRET')->assertDontSee('pw_INLINESECRET');

    Artisan::call('webhooks:failed', ['--json' => true]);
    expect(Artisan::output())->toContain('Stripe rejected')->not->toContain('sk_live_PAYLOADSECRET')->not->toContain('pw_INLINESECRET');
});

it('masks the signing secret wherever it appears in free text', function () {
    $this->asLocal();
    config()->set('webhook-client.configs.0.signing_secret', 'whsec_SIGNINGSECRET');
    app()->forgetScopedInstances();
    $call = secretCall(['exception' => ['code' => 0, 'message' => 'HMAC with whsec_SIGNINGSECRET did not match', 'trace' => '']]);

    $this->get("/webhook-console/calls/{$call->id}")->assertSee('HMAC with')->assertDontSee('whsec_SIGNINGSECRET');
});

it('masks secrets in the failed_jobs exception of a queue failure', function () {
    $this->asLocal();
    $call = secretCall(['exception' => null]);
    $command = serialize(new ThrowingJob($call));
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => ThrowingJob::class, 'data' => ['commandName' => ThrowingJob::class, 'command' => $command]]),
        'exception' => "RuntimeException: upstream refused api_key=sk_live_PAYLOADSECRET in /app/Job.php:12\nStack trace:\n#0 Bearer abc_BEARERSECRET",
        'failed_at' => now()->addSecond(),
    ]);

    $this->get("/webhook-console/calls/{$call->id}")->assertSee('Failed in queue')->assertSee('upstream refused')
        ->assertDontSee('sk_live_PAYLOADSECRET')->assertDontSee('abc_BEARERSECRET');
});

it('searches only what is shown, so a guessed secret finds nothing', function () {
    $this->asLocal();
    secretCall(['exception' => null]);

    $this->get('/webhook-console?q=sk_live_PAYLOADSECRET')->assertSee('No webhook calls match')->assertDontSee('charge.failed');
    $this->get('/webhook-console?q=tok_URLSECRET')->assertSee('No webhook calls match');
    $this->get('/webhook-console?q=cus_visible')->assertSee('charge.failed');
    $this->get('/webhook-console?q='.urlencode(Masker::MASK))->assertSee('No webhook calls match');
});

it('says when failed_jobs has more rows than it reads', function () {
    $this->asLocal();
    config()->set('webhook-console.failed_jobs.scan_limit', 1);
    $call = secretCall(['exception' => null]);
    foreach ([1, 2] as $i) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => ThrowingJob::class, 'data' => ['commandName' => ThrowingJob::class, 'command' => serialize(new ThrowingJob($call))]]),
            'exception' => 'RuntimeException: x', 'failed_at' => now()->addSeconds($i),
        ]);
    }

    $this->get('/webhook-console')->assertSee('only the newest were checked');
    $this->artisan('webhooks:failed')->expectsOutputToContain('Only the newest 1 failed_jobs rows were checked')->assertSuccessful();

    config()->set('webhook-console.failed_jobs.scan_limit', 1000);
    app()->forgetScopedInstances();
    $this->get('/webhook-console')->assertDontSee('only the newest were checked');
});

it('masks free text by value, by key=value pair and by bearer token', function () {
    $masker = new Masker(['password', 'token', 'api_key'], [], ['whsec_SIGNING']);

    expect($masker->text('key whsec_SIGNING, password=hunter22 and "api_key": "k_123" token: t_9 Bearer abc.def', ['cus_secret']))
        ->toBe('key '.Masker::MASK.', password='.Masker::MASK.' and "api_key": "'.Masker::MASK.'" token: '.Masker::MASK.' Bearer '.Masker::MASK)
        ->and($masker->text('nothing secret here for cus_secret', ['cus_secret']))->toBe('nothing secret here for '.Masker::MASK)
        ->and((new Masker(['authorization', 'password']))->text('rejected (password=pw_1, Authorization: Bearer tok_BEARER, authorization=Basic dXNlcjpw)'))
        ->toBe('rejected (password='.Masker::MASK.', Authorization: Bearer '.Masker::MASK.', authorization=Basic '.Masker::MASK.')')
        ->and($masker->secretsOf(['a' => ['password' => 'p_1234'], 'b' => 'visible'], ['x-token' => ['t_1234']], 'https://x.test/?api_key=k_1234&b=2'))
        ->toBe(['p_1234', 't_1234', 'k_1234']);
});

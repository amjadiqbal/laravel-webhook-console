<?php

use Spatie\WebhookClient\Models\WebhookCall;

function agedCall(int $days, string $name = 'default'): WebhookCall
{
    $call = WebhookCall::create(['name' => $name, 'url' => 'u', 'payload' => ['type' => 'x']]);
    $call->forceFill(['created_at' => now()->subDays($days)])->save();

    return $call;
}

it('deletes calls older than --days with --force', function () {
    agedCall(10);
    agedCall(10, 'github');
    agedCall(1);

    $this->artisan('webhooks:prune --days=5 --force')->expectsOutputToContain('Deleted 2 calls older than 5 days')->assertSuccessful();

    expect(WebhookCall::count())->toBe(1);
});

it('defaults to webhook-client delete_after_days and honours --name', function () {
    config()->set('webhook-client.delete_after_days', 30);
    agedCall(40);
    agedCall(40, 'github');
    agedCall(20);

    $this->artisan('webhooks:prune --name=github --force')->expectsOutputToContain("Deleted 1 call older than 30 days for 'github'")->assertSuccessful();

    expect(WebhookCall::pluck('name')->sort()->values()->all())->toBe(['default', 'default']);
});

it('asks first, and needs --force when it cannot ask', function () {
    agedCall(10);

    $this->artisan('webhooks:prune --days=5')->expectsConfirmation('Permanently delete 1 call older than 5 days?', 'no')->assertFailed();
    $this->artisan('webhooks:prune --days=5 --pretend')->expectsOutputToContain('Would delete 1 call older than 5 days')->assertSuccessful();

    expect(WebhookCall::count())->toBe(1);
});

it('refuses without a usable number of days', function () {
    config()->set('webhook-client.delete_after_days', null);

    $this->artisan('webhooks:prune --force')->assertExitCode(2);
    $this->artisan('webhooks:prune --days=0 --force')->assertExitCode(2);
});

it('says "1 day", not "1 days"', function () {
    agedCall(3);

    $this->artisan('webhooks:prune --days=1 --pretend')->expectsOutputToContain('Would delete 1 call older than 1 day.')->assertSuccessful();
});

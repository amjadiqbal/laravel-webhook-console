<?php

use AmjadIqbal\WebhookConsole\Filters;
use Carbon\CarbonImmutable;

it('parses relative and absolute dates', function () {
    CarbonImmutable::setTestNow('2026-10-21 12:00:00');

    expect(Filters::date('2h')?->toDateTimeString())->toBe('2026-10-21 10:00:00')
        ->and(Filters::date('7d')?->toDateTimeString())->toBe('2026-10-14 12:00:00')
        ->and(Filters::date('2026-10-01')?->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and(Filters::date('2026-10-01', endOfDay: true)?->toDateTimeString())->toBe('2026-10-01 23:59:59')
        ->and(Filters::date('not a date'))->toBeNull()
        ->and(Filters::date(''))->toBeNull();
});

it('builds from request input and ignores unknown statuses', function () {
    $filters = Filters::fromInput(['name' => 'stripe', 'status' => 'bogus', 'q' => ' evt_1 ', 'to' => '2026-10-02']);

    expect($filters->name)->toBe('stripe')
        ->and($filters->status)->toBe('')
        ->and($filters->search)->toBe('evt_1')
        ->and($filters->to?->toDateTimeString())->toBe('2026-10-02 23:59:59')
        ->and($filters->toQuery())->toBe(['name' => 'stripe', 'to' => '2026-10-02', 'q' => 'evt_1']);
});

it('rejects an unknown status in code', function () {
    new Filters(status: 'pending');
})->throws(InvalidArgumentException::class);

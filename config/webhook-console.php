<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The dashboard is served at /{path}. Three gates decide who may do what,
    | and each allows the local environment only until you define it yourself
    | (see the README):
    |
    |   viewWebhookConsole    see calls, payloads, headers and failures
    |   replayWebhookCalls    replay one call or bulk-retry failed calls
    |   pruneWebhookCalls     delete calls older than a number of days
    |
    | The view gate is always checked after the middleware below, so emptying
    | this list cannot expose the dashboard.
    |
    */

    'dashboard' => [
        'enabled' => env('WEBHOOK_CONSOLE_DASHBOARD', true),
        'path' => env('WEBHOOK_CONSOLE_PATH', 'webhook-console'),
        'domain' => env('WEBHOOK_CONSOLE_DOMAIN'),
        'middleware' => ['web'],
        'per_page' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    |
    | Payload keys, header names and URL query parameters whose name contains
    | one of these words (case-insensitive) are shown as "••••••". The
    | signature header of every webhook-client config is always masked too.
    | Masking applies everywhere: the dashboard, copy buttons and artisan.
    |
    */

    'mask' => [
        'password', 'passwd', 'secret', 'token', 'authorization', 'signature',
        'api_key', 'apikey', 'api-key', 'cookie', 'session', 'private_key',
        'card_number', 'cvc', 'cvv', 'iban', 'ssn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed queue jobs
    |--------------------------------------------------------------------------
    |
    | webhook-client stores an exception on the call only when dispatching the
    | job fails (or the job throws on the sync queue). When your process job
    | throws on a real queue worker, the failure is in failed_jobs instead.
    | The console reads the newest rows of failed_jobs (database drivers
    | only) and links them to their webhook call. This is how many rows it
    | reads per request.
    |
    */

    'failed_jobs' => [
        'enabled' => true,
        'scan_limit' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk retry
    |--------------------------------------------------------------------------
    |
    | The most calls one "Retry failed" click (or webhooks:replay --failed
    | without --limit) will re-dispatch.
    |
    */

    'bulk_limit' => 100,

];

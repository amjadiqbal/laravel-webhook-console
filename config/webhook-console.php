<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The dashboard is served at /{path}. Three gates decide who may do what
    | (see the README):
    |
    |   viewWebhookConsole    see calls, payloads, headers and failures
    |                         default: the local environment only
    |   replayWebhookCalls    replay one call or bulk-retry failed calls
    |                         default: denied until you define it
    |   pruneWebhookCalls     delete calls older than a number of days
    |                         default: denied until you define it
    |
    | Replay and prune also need a signed-in user unless you set
    | actions_require_user to false (for an app without authentication).
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
        'actions_require_user' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    |
    | Payload keys, header names and URL query parameters whose name contains
    | one of these words (case-insensitive) are shown as "••••••". The
    | signature header of every webhook-client config is always masked too.
    | Exception messages, stack traces and failed_jobs text are masked as
    | well: every secret value of the call, every signing secret, key=value
    | pairs with these words, and bearer tokens. Masking applies everywhere:
    | the dashboard, copy buttons, search and artisan.
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
    | Search
    |--------------------------------------------------------------------------
    |
    | Searching the payload and URL matches against the masked values only, so
    | a secret cannot be confirmed by guessing it. The database narrows the
    | candidates first; this is how many of the newest candidates are checked.
    |
    */

    'search' => [
        'scan_limit' => 2000,
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

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png">
    <img alt="Laravel Webhook Console: see, inspect and replay every webhook your Laravel app receives with spatie/laravel-webhook-client" src="art/banner-light.png" width="100%">
  </picture>
</p>

# Laravel Webhook Console: a dashboard and replay tool for spatie/laravel-webhook-client

[`spatie/laravel-webhook-client`](https://github.com/spatie/laravel-webhook-client) stores every webhook your
Laravel app accepts in the `webhook_calls` table, but it has no screen to look at them. This package adds one:
a gated dashboard that lists your Stripe, GitHub, Shopify or any other webhooks by config, status and date,
shows each payload and its headers **with secrets masked**, shows **why a webhook failed**, and lets you
**replay one call or bulk-retry every failure** with a click or from artisan.

It is read-only by default: out of the box the dashboard opens in the `local` environment only, and replay
and prune stay off everywhere until you define their gates for signed-in users.

Docs and demo: https://amjad.com.pk/laravel/packages/laravel-webhook-console

[![Latest Version](https://img.shields.io/packagist/v/amjadiqbal/laravel-webhook-console.svg?style=flat-square)](https://packagist.org/packages/amjadiqbal/laravel-webhook-console)
[![PHP Version](https://img.shields.io/packagist/php-v/amjadiqbal/laravel-webhook-console?style=flat-square)](https://packagist.org/packages/amjadiqbal/laravel-webhook-console)
[![License](https://img.shields.io/packagist/l/amjadiqbal/laravel-webhook-console.svg?style=flat-square)](LICENSE.md)

<p align="center">
  <img alt="The Webhook Console dashboard: Stripe, GitHub, Shopify and Paddle webhook calls, two failures (one with a stored exception, one failed on the queue worker), filters by config, status, date and payload search, and Replay selected / Retry failed buttons" src="art/screenshot.png" width="100%">
</p>

```bash
composer require amjadiqbal/laravel-webhook-console
```

Then open `/webhook-console` in your local environment.

## Why failures are hard to see without it

webhook-client writes an `exception` on a call only when **dispatching** its process job fails, or when the
job throws on the `sync` queue. When your job throws on a real queue worker, which is the usual case in
production, the call keeps `exception = NULL` and the failure only exists in `failed_jobs`, with nothing that
points back to the webhook.

The console reads both. It links each `failed_jobs` row to its webhook call (from the serialized job's model
id, read with a regex and never unserialized), so every call has one of three statuses:

| Status | Meaning |
|---|---|
| **Failed** | webhook-client stored an exception on the call |
| **Failed in queue** | the process job failed on a queue worker and is in `failed_jobs` |
| **No error** | nothing failed that the console can see |

## Requirements

- PHP 8.3+, Laravel 12 or 13
- `spatie/laravel-webhook-client` 3.4+ (installed for you), already receiving webhooks
- Queue failures are read from the `database` or `database-uuids` failed job driver (Laravel's default).
  Other drivers still get everything else; the dashboard says why queue failures are missing.

## Access: three gates

| Gate | Allows | Default |
|---|---|---|
| `viewWebhookConsole` | the dashboard, payloads, headers, failures | allowed in the `local` environment only |
| `replayWebhookCalls` | Replay, Replay selected, Retry failed | **denied** until you define it |
| `pruneWebhookCalls` | deleting old calls from the dashboard | **denied** until you define it |

Out of the box you can look at your webhooks locally and change nothing. Outside `local` nobody gets in, and
nobody can replay or prune anywhere, until you define the gates, for example in `AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewWebhookConsole', fn (User $user) => $user->is_admin);
Gate::define('replayWebhookCalls', fn (User $user) => $user->is_admin);
Gate::define('pruneWebhookCalls', fn (User $user) => false); // view and replay only
```

Replay and prune also require a signed-in user, whatever the gate says. For a local app with no
authentication at all, set `dashboard.actions_require_user` to `false` and define the gates with a nullable
user (`fn ($user = null) => app()->isLocal()`). A guest never passes a gate that type-hints `User`. Someone
who can view but not replay sees the dashboard in read-only mode, without the replay buttons; the replay and
prune routes return 403 for them.

## What you can do

**List and filter.** Every stored call with its config name, event (Stripe's `type`, GitHub's `action`,
Shopify's `topic`...), status, error and age. Filter by config, status, date range, or search inside the
payload and URL (an event id, a customer email, an order number). Search matches what the dashboard shows,
so masked secrets can't be found by guessing them. Works on MySQL, MariaDB, PostgreSQL and SQLite.

**Inspect one call.** The payload as pretty JSON with a copy button, the stored headers, uploaded file names
and sizes (never their content), the stored exception with its stack trace, and the `failed_jobs` exception
for queue failures.

**Masking.** Payload keys, header names and URL query parameters whose name contains `password`, `secret`,
`token`, `authorization`, `signature`, `api_key`, `cookie`, `card_number`, `cvc`, `iban` and similar are
shown as `••••••`, at any depth. The signature header of every webhook-client config is always masked. The
list is in the config file. The same secrets are masked inside free text too: stored exception messages,
stack traces and `failed_jobs` exceptions, where every secret value of the call, every webhook-client
signing secret, `secret_key=value` style pairs and bearer tokens are hidden. Masking applies in the copy
button and in artisan output as well.

**Replay.** Dispatches the call's configured `process_webhook_job` again, exactly as webhook-client does for a
new webhook: the job from the call's config, `clearException()`, `dispatch()`, and `saveException()` if
dispatching throws. Nothing is sent over the network and the signature is not checked again; the stored
call is simply processed again, so your job should be safe to run twice for the same event (most webhook
handlers already are, because providers retry too).

**Bulk retry.** Tick calls and click *Replay selected*, or click *Retry failed* to replay every failure that
matches the current filters (up to `bulk_limit`, 100 by default). Calls are replayed oldest first.

**Prune.** Delete calls older than N days, for all configs or one, from the dashboard or with
`php artisan webhooks:prune`. Both are a mass delete, like webhook-client's own pruning: no model events fire.
webhook-client can also prune on a schedule itself with
`php artisan model:prune --model="Spatie\WebhookClient\Models\WebhookCall"`, using its `delete_after_days`.

## Artisan

```bash
php artisan webhooks:failed                       # failed calls (stored exception or failed queue job)
php artisan webhooks:failed --since=24h --strict  # exit code 1 when anything failed: CI, cron, monitoring
php artisan webhooks:failed --name=stripe --json

php artisan webhooks:replay 41 42                 # replay these calls
php artisan webhooks:replay --failed --dry-run    # what would be replayed
php artisan webhooks:replay --failed --since=2h --force

php artisan webhooks:prune --days=30 --force      # delete calls older than 30 days
php artisan webhooks:prune --name=github --pretend # count only (default days: webhook-client delete_after_days)
```

`webhooks:replay` and `webhooks:prune` ask before acting; non-interactive and scheduled runs need `--force`.
Exit codes, so they are safe in scripts:

| Command | 0 | 1 | 2 |
|---|---|---|---|
| `webhooks:failed` | always, without `--strict` | `--strict` and a failure was found | bad `--since` |
| `webhooks:replay` | every call dispatched, nothing to replay, or `--dry-run` | a call failed to dispatch or was skipped (its config is gone or has no job), or you answered no | no IDs and no `--failed`, or bad `--since` |
| `webhooks:prune` | deleted, nothing to delete, or `--pretend` | you answered no | no usable `--days` |

To be told about failures and keep the table small, schedule both:

```php
// routes/console.php
Schedule::command('webhooks:failed --since=1h --strict')->hourly()->emailOutputOnFailure('ops@example.com');
Schedule::command('webhooks:prune --days=30 --force')->daily();
```

## Custom webhook models

Each webhook-client config can set its own `webhook_model`. The console reads every config:

- A call is replayed through its own config's model, so a job that type-hints your model gets that model.
- If configs use models with **different tables**, the dashboard shows a table switcher and
  `webhooks:replay --source=<table>` picks one; `webhooks:failed` covers all of them.
- Calls whose config name no longer exists are still listed, but can't be replayed (the dashboard says why).
  The same goes for the published default config, whose `process_webhook_job` is empty.

## Configuration

```bash
php artisan vendor:publish --tag=webhook-console-config
```

| Key | Default | |
|---|---|---|
| `dashboard.enabled` | `true` (`WEBHOOK_CONSOLE_DASHBOARD`) | `false` removes the routes |
| `dashboard.path` | `webhook-console` (`WEBHOOK_CONSOLE_PATH`) | |
| `dashboard.domain` | `null` (`WEBHOOK_CONSOLE_DOMAIN`) | |
| `dashboard.middleware` | `['web']` | the view gate is always added after these |
| `dashboard.per_page` | `50` | |
| `dashboard.actions_require_user` | `true` | replay and prune need a signed-in user |
| `mask` | password, secret, token, ... | words matched inside key and header names |
| `failed_jobs.enabled` | `true` | read `failed_jobs` for queue failures |
| `failed_jobs.scan_limit` | `1000` | newest `failed_jobs` rows read per request; the dashboard and `webhooks:failed` say so when there are more |
| `search.scan_limit` | `2000` | newest database candidates checked per search; when a search with visible results may be missing older ones, the dashboard says so (without a count) |
| `bulk_limit` | `100` | the most calls one bulk retry replays |

The views can be published with `--tag=webhook-console-views`.

### How a replayed queue failure stops counting

Replaying touches the call (`updated_at`). A `failed_jobs` row older than the call's `updated_at` belongs to
an earlier attempt, so the call shows as *No error* again, with the old failure still visible on its page as
*Earlier queue failure (replayed since)*. If the replayed job fails again, a newer row appears and the call is
*Failed in queue* again. The `failed_jobs` rows themselves are never changed or deleted.

## Testing

```bash
composer test               # Pest, on SQLite
scripts/integration.sh      # the same suite on real MySQL 8.4 and PostgreSQL 17 (Docker)
scripts/ci-local.sh         # PHP 8.3/8.4/8.5 x Laravel 12/13, Pint, PHPStan, then the integration run
```

## Need this built into your app, or something custom?

I build and maintain Laravel apps that live on webhooks: payment providers, GitHub and Shopify apps, and the
queue workers behind them. If you would rather have someone set up reliable webhook handling with you, or
you need something this package does not do,
[get in touch](https://amjad.com.pk/contact?utm_source=github&utm_medium=readme&utm_campaign=laravel-webhook-console).

## Support

- 🐛 **Bug or feature request**: [open an issue](https://github.com/amjadiqbal/laravel-webhook-console/issues)
- 🔒 **Security issue**: please report privately via GitHub's security tab

## Need this customised, or something built?

I'm available for custom development, package integration, and technical consulting.

[![Hire me on Upwork](https://img.shields.io/badge/Hire%20me-Upwork-6FDA44?style=flat-square&logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/amjadkhatri)

## More packages

Part of a family of open-source packages. See [all of them](https://github.com/amjadiqbal?tab=repositories).

| | |
|---|---|
| [laravel-mysql-advisor](https://github.com/amjadiqbal/laravel-mysql-advisor) | MySQL and MariaDB health checks with the SQL to fix each problem |
| [kiln](https://github.com/amjadiqbal/kiln) | Deploy-time OPcache control for Laravel |
| [laravel-logpulse](https://github.com/amjadiqbal/laravel-logpulse) | Log health monitoring and alerting |
| [laravel-driver-js](https://github.com/amjadiqbal/laravel-driver-js) | Product tours and onboarding for Laravel |

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md). This package is independent and is not made or endorsed by Spatie;
`spatie/laravel-webhook-client` is their MIT-licensed package.

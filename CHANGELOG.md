# Changelog

All notable changes to `laravel-webhook-console` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-10-21

### Security
- Replay and prune are now denied by default. Before, all three gates allowed anyone, guests included, in
  the `local` environment. `viewWebhookConsole` still defaults to the local environment only;
  `replayWebhookCalls` and `pruneWebhookCalls` must be defined by the app.
- Replay and prune now require a signed-in user as well as their gate (`dashboard.actions_require_user`,
  default `true`, for apps without authentication).
- Stored exception messages, stack traces and `failed_jobs` exceptions are now masked like payloads: the
  call's secret values, webhook-client signing secrets, `secret_key=value` pairs and bearer tokens. This
  covers the dashboard and `webhooks:failed`.
- Payload search now matches only the masked form of each call, so a viewer can no longer confirm a guessed
  secret by searching for it (`search.scan_limit`).

### Added
- `webhooks:prune` (`--days`, defaulting to webhook-client's `delete_after_days`, plus `--name`, `--source`,
  `--pretend` and `--force`), schedulable.
- A notice on the dashboard and in `webhooks:failed` when `failed_jobs` holds more rows than
  `failed_jobs.scan_limit`, so older queue failures no longer show as "No error" silently. JSON output has
  `failed_jobs_limited`.

### Changed
- README: the real gate defaults, exit codes for all three commands, and the fact that prune is a mass
  delete with no model events.

## [1.0.0] - 2026-10-21

### Added
- Gated dashboard for `spatie/laravel-webhook-client`: every stored webhook call with its config, event name
  (from the payload or the provider's event header), status, error and age; filters by config, status, date
  range and a search inside the payload and URL.
- Call page: payload as JSON with a copy button, stored headers, attachment names and sizes, the stored
  exception with its trace, and the `failed_jobs` exception for queue failures.
- Failure status that includes jobs which failed on a queue worker: `failed_jobs` rows are linked to their
  call from the serialized job's model id (read with a regex, never unserialized). Database failed job
  drivers only; other drivers are reported.
- Secret masking for payload keys, header names and URL query parameters (configurable word list), plus the
  signature header of every webhook-client config.
- Replay of one call, *Replay selected* and *Retry failed* (bulk, oldest first, capped by `bulk_limit`),
  re-dispatching the configured `process_webhook_job` the way webhook-client does.
- Prune calls older than N days, for all configs or one.
- `webhooks:failed` (`--name`, `--since`, `--limit`, `--json`, `--strict` exit code) and `webhooks:replay`
  (IDs or `--failed`, `--dry-run`, `--force`, `--source`).
- Three gates, `viewWebhookConsole`, `replayWebhookCalls` and `pruneWebhookCalls`, local environment only
  until the app defines them.
- Custom `webhook_model` support, including models with their own table.
- `php artisan about` entry.

[Unreleased]: https://github.com/amjadiqbal/laravel-webhook-console/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/amjadiqbal/laravel-webhook-console/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/amjadiqbal/laravel-webhook-console/releases/tag/v1.0.0

# Changelog

All notable changes to `laravel-webhook-console` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/amjadiqbal/laravel-webhook-console/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/amjadiqbal/laravel-webhook-console/releases/tag/v1.0.0

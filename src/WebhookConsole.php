<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Reads webhook calls the way webhook-client stores them. A "source" is one table: usually
 * webhook_calls, more when configs point their webhook_model at models with other tables.
 */
final class WebhookConsole
{
    /** @var array<string, Endpoint>|null */
    private ?array $endpoints = null;

    /** @var array<string, list<string>> */
    private array $activeQueueFailures = [];

    public function __construct(
        private readonly Config $config,
        private readonly FailedJobs $failedJobs,
    ) {}

    /**
     * @return array<string, Endpoint> keyed by config name
     */
    public function endpoints(): array
    {
        if ($this->endpoints !== null) {
            return $this->endpoints;
        }

        $this->endpoints = [];

        foreach ((array) $this->config->get('webhook-client.configs', []) as $config) {
            if (is_array($config)) {
                $endpoint = Endpoint::fromConfig($config);
                $this->endpoints[$endpoint->name] = $endpoint;
            }
        }

        return $this->endpoints;
    }

    public function endpoint(string $name): ?Endpoint
    {
        return $this->endpoints()[$name] ?? null;
    }

    /**
     * @return array<string, class-string<WebhookCall>> table => the model that reads it
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->endpoints() as $endpoint) {
            $sources[$endpoint->table()] ??= $endpoint->modelClass;
        }

        return $sources !== [] ? $sources : [(new WebhookCall)->getTable() => WebhookCall::class];
    }

    /** The given table if it is a known source, else the first one. */
    public function source(?string $table = null): string
    {
        $sources = $this->sources();

        return $table !== null && isset($sources[$table]) ? $table : (string) array_key_first($sources);
    }

    /**
     * The model class a call is loaded with for replay: its config's webhook_model.
     *
     * @return class-string<WebhookCall>
     */
    public function modelFor(string $table, ?string $name = null): string
    {
        $endpoint = $name !== null ? $this->endpoint($name) : null;

        return $endpoint !== null && $endpoint->table() === $table ? $endpoint->modelClass : $this->sources()[$this->source($table)];
    }

    /**
     * @return Builder<WebhookCall>
     */
    public function newQuery(string $table): Builder
    {
        $class = $this->sources()[$this->source($table)];

        return $class::query();
    }

    public function find(string $table, string|int $id): ?WebhookCall
    {
        $call = $this->newQuery($table)->find($id);

        return $call instanceof WebhookCall ? $call : null;
    }

    /**
     * @return Builder<WebhookCall>
     */
    public function query(string $table, Filters $filters): Builder
    {
        $query = $this->newQuery($table);
        $key = $query->getModel()->getQualifiedKeyName();

        if ($filters->name !== null) {
            $query->where('name', $filters->name);
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        if ($filters->search !== null) {
            $like = '%'.$filters->search.'%';
            $payload = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'CAST(payload AS TEXT)' : 'payload';
            $query->where(fn (Builder $q) => $q->whereRaw("{$payload} LIKE ?", [$like])->orWhere('url', 'like', $like));
        }

        $queueFailed = in_array($filters->status, ['failures', 'queue_failed', 'ok'], true) ? $this->activeQueueFailureIds($table) : [];

        match ($filters->status) {
            'failed' => $query->whereNotNull('exception'),
            'queue_failed' => $query->whereNull('exception')->whereIn($key, $queueFailed),
            'failures' => $query->where(fn (Builder $q) => $q->whereNotNull('exception')->orWhereIn($key, $queueFailed)),
            'ok' => $query->whereNull('exception')->whereNotIn($key, $queueFailed),
            default => null,
        };

        return $query->orderByDesc($key);
    }

    /**
     * The event name: from the header providers send it in (GitHub, GitLab, Shopify, Bitbucket), when
     * stored, else from the top of the payload (Stripe "type", Paddle "event_type", GitHub "action"...).
     */
    public function event(WebhookCall $call): ?string
    {
        $headers = is_array($call->headers) ? array_change_key_case($call->headers) : [];

        foreach (['x-github-event', 'x-gitlab-event', 'x-shopify-topic', 'x-event-key'] as $name) {
            $value = $headers[$name][0] ?? $headers[$name] ?? null;

            if (is_scalar($value) && $value !== '') {
                $action = is_array($call->payload) && is_scalar($call->payload['action'] ?? null) ? '.'.$call->payload['action'] : '';

                return mb_strimwidth($value.$action, 0, 60, '…');
            }
        }

        $payload = $call->payload;

        foreach (['type', 'event', 'event_type', 'eventType', 'event_name', 'topic', 'object_kind', 'action'] as $key) {
            if (is_array($payload) && isset($payload[$key]) && is_scalar($payload[$key]) && $payload[$key] !== '') {
                return mb_strimwidth((string) $payload[$key], 0, 60, '…');
            }
        }

        return null;
    }

    public function status(WebhookCall $call): Status
    {
        if ($call->exception !== null) {
            return Status::Failed;
        }

        return $this->activeQueueFailure($call) !== null ? Status::QueueFailed : Status::Ok;
    }

    /** The call's newest failed_jobs row, also when it was replayed since. */
    public function queueFailure(WebhookCall $call): ?QueueFailure
    {
        return $this->failedJobs->forCall($call);
    }

    /**
     * The queue failure that still stands: replaying touches the call, so a failure older than
     * the call's updated_at was followed by a replay.
     */
    public function activeQueueFailure(WebhookCall $call): ?QueueFailure
    {
        $failure = $this->queueFailure($call);

        return $failure !== null && ($call->updated_at === null || $failure->failedAt->greaterThanOrEqualTo($call->updated_at)) ? $failure : null;
    }

    /**
     * @return list<string>
     */
    public function activeQueueFailureIds(string $table): array
    {
        $table = $this->source($table);

        if (isset($this->activeQueueFailures[$table])) {
            return $this->activeQueueFailures[$table];
        }

        $failures = $this->failedJobs->forTable($table);
        $active = [];

        foreach (array_chunk(array_keys($failures), 500) as $ids) {
            $query = $this->newQuery($table);
            $rows = $query->whereIn($query->getModel()->getKeyName(), $ids)->get();

            foreach ($rows as $call) {
                if ($this->activeQueueFailure($call) !== null) {
                    $active[] = (string) $call->getKey();
                }
            }
        }

        return $this->activeQueueFailures[$table] = $active;
    }

    /**
     * @return array<string, int> config name => stored calls, including names no longer configured
     */
    public function names(string $table): array
    {
        $counts = array_fill_keys(array_keys(array_filter(
            $this->endpoints(),
            fn (Endpoint $e): bool => $e->table() === $this->source($table),
        )), 0);

        foreach ($this->newQuery($table)->toBase()->selectRaw('name, count(*) as aggregate')->groupBy('name')->get() as $row) {
            $counts[(string) $row->name] = (int) $row->aggregate;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{total: int, failed: int, queue_failed: int, last_day: int}
     */
    public function stats(string $table): array
    {
        return [
            'total' => $this->newQuery($table)->count(),
            'failed' => $this->newQuery($table)->whereNotNull('exception')->count(),
            'queue_failed' => $this->query($table, new Filters(status: 'queue_failed'))->count(),
            'last_day' => $this->newQuery($table)->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Deletes calls created more than $days days ago (optionally of one config only), the same
     * mass delete webhook-client's own prunable() does. Returns the number deleted.
     */
    public function prune(string $table, int $days, ?string $name = null): int
    {
        return $this->pruneQuery($table, $days, $name)->delete();
    }

    /**
     * @return Builder<WebhookCall>
     */
    public function pruneQuery(string $table, int $days, ?string $name = null): Builder
    {
        $query = $this->newQuery($table)->where('created_at', '<', now()->subDays(max(1, $days)));

        return $name !== null ? $query->where('name', $name) : $query;
    }

    public function failedJobsProblem(): ?string
    {
        return $this->failedJobs->unavailableReason();
    }

    public function masker(): Masker
    {
        $signatureHeaders = array_values(array_filter(array_map(
            fn (Endpoint $e): string => $e->signatureHeader,
            $this->endpoints(),
        )));

        /** @var list<string> $words */
        $words = array_values(array_filter((array) $this->config->get('webhook-console.mask', []), 'is_string'));

        return new Masker($words, $signatureHeaders);
    }
}

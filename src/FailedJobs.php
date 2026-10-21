<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Spatie\WebhookClient\Models\WebhookCall;
use Throwable;

/**
 * Links failed_jobs rows to webhook calls. The job payload holds the serialized process job,
 * whose WebhookCall is a ModelIdentifier (class + id); that is read with a regex, never
 * unserialized, so no class in a failed payload is ever instantiated.
 */
final class FailedJobs
{
    /** @var array<string, array<string, QueueFailure>> newest failure per call id, per table */
    private array $byTable = [];

    /** @var array<string, string|null> */
    private array $tables = [];

    private ?string $unavailable = null;

    private bool $checked = false;

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly Encrypter $encrypter,
    ) {}

    /** Why failed_jobs cannot be read, or null when it can. */
    public function unavailableReason(): ?string
    {
        if ($this->checked) {
            return $this->unavailable;
        }

        $this->checked = true;
        $driver = (string) $this->config->get('queue.failed.driver', 'database-uuids');

        if (! $this->config->get('webhook-console.failed_jobs.enabled', true)) {
            return $this->unavailable = 'Reading failed_jobs is switched off (webhook-console.failed_jobs.enabled).';
        }

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return $this->unavailable = "The failed job driver is '{$driver}'; only the database drivers can be read.";
        }

        try {
            $connection = $this->db->connection($this->config->get('queue.failed.database'));

            if (! $connection->getSchemaBuilder()->hasTable($this->failedTable())) {
                return $this->unavailable = "The {$this->failedTable()} table does not exist.";
            }
        } catch (Throwable $e) {
            return $this->unavailable = 'Could not read failed_jobs: '.$e->getMessage();
        }

        return null;
    }

    /**
     * The newest queue failure of each call stored in $table, keyed by call id.
     *
     * @return array<string, QueueFailure>
     */
    public function forTable(string $table): array
    {
        if (isset($this->byTable[$table])) {
            return $this->byTable[$table];
        }

        if ($this->unavailableReason() !== null) {
            return $this->byTable[$table] = [];
        }

        $failures = [];
        $rows = $this->db->connection($this->config->get('queue.failed.database'))
            ->table($this->failedTable())
            ->orderByDesc('failed_at')
            ->limit(max(1, (int) $this->config->get('webhook-console.failed_jobs.scan_limit', 1000)))
            ->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $link = $this->parse((string) ($row['payload'] ?? ''));

            if ($link === null || $this->tableOf($link['model']) !== $table || isset($failures[$link['id']])) {
                continue;
            }

            $failures[$link['id']] = new QueueFailure(
                id: (string) ($row['uuid'] ?? $row['id'] ?? ''),
                jobClass: $link['job'],
                connection: (string) ($row['connection'] ?? ''),
                queue: (string) ($row['queue'] ?? ''),
                exception: (string) ($row['exception'] ?? ''),
                failedAt: CarbonImmutable::parse((string) $row['failed_at']),
            );
        }

        return $this->byTable[$table] = $failures;
    }

    public function forCall(WebhookCall $call): ?QueueFailure
    {
        return $this->forTable($call->getTable())[(string) $call->getKey()] ?? null;
    }

    /**
     * @return array{job: string, model: string, id: string}|null
     */
    public function parse(string $payload): ?array
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return null;
        }

        $job = $data['data']['commandName'] ?? $data['displayName'] ?? null;
        $command = $data['data']['command'] ?? null;

        if (! is_string($job) || ! is_string($command) || ! is_subclass_of($job, ProcessWebhookJob::class)) {
            return null;
        }

        if (! str_starts_with($command, 'O:')) {
            try {
                $command = (string) $this->encrypter->decrypt($command, false); // ShouldBeEncrypted jobs
            } catch (Throwable) {
                return null;
            }
        }

        preg_match_all(
            '/"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier":\d+:\{s:5:"class";s:\d+:"([^"]+)";s:2:"id";(?:i:(\d+)|s:\d+:"([^"]*)")/',
            $command,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            if (is_a($match[1], WebhookCall::class, true)) {
                return ['job' => $job, 'model' => $match[1], 'id' => ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')];
            }
        }

        return null;
    }

    private function tableOf(string $modelClass): ?string
    {
        return $this->tables[$modelClass] ??= is_a($modelClass, WebhookCall::class, true) ? (new $modelClass)->getTable() : null;
    }

    private function failedTable(): string
    {
        return (string) $this->config->get('queue.failed.table', 'failed_jobs');
    }
}

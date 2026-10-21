<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole\Console;

use AmjadIqbal\WebhookConsole\Filters;
use AmjadIqbal\WebhookConsole\Status;
use AmjadIqbal\WebhookConsole\WebhookConsole;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class FailedCommand extends Command
{
    protected $signature = 'webhooks:failed
        {--name= : Only calls of this webhook-client config}
        {--since= : Only calls received since then: 30m, 2h, 7d, 1w or a date}
        {--limit=50 : The most calls to list per table}
        {--json : Print JSON instead of a table}
        {--strict : Exit with code 1 when any failed call is found (for CI and monitoring)}';

    protected $description = 'List webhook calls that failed (stored exception or failed queue job)';

    public function handle(WebhookConsole $console): int
    {
        $since = $this->option('since');

        if (is_string($since) && $since !== '' && Filters::date($since) === null) {
            $this->components->error("Could not understand --since={$since}. Use 30m, 2h, 7d, 1w or a date.");

            return self::INVALID;
        }

        $filters = new Filters(
            name: is_string($this->option('name')) && $this->option('name') !== '' ? $this->option('name') : null,
            status: 'failures',
            from: is_string($since) ? Filters::date($since) : null,
        );

        $rows = [];

        foreach (array_keys($console->sources()) as $table) {
            foreach ($console->query($table, $filters)->limit(max(1, (int) $this->option('limit')))->get() as $call) {
                $status = $console->status($call);
                $rows[] = [
                    'id' => $call->getKey(),
                    'table' => $table,
                    'name' => (string) $call->name,
                    'event' => $console->event($call),
                    'status' => $status->value,
                    'received_at' => $call->created_at?->toIso8601String(),
                    'error' => $status === Status::Failed
                        ? (string) ($call->exception['message'] ?? '')
                        : ($console->activeQueueFailure($call)?->message() ?? ''),
                ];
            }
        }

        $problem = $console->failedJobsProblem();

        if ($this->option('json')) {
            $this->line((string) json_encode(['failed_jobs_read' => $problem === null, 'calls' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($rows === []) {
            $this->components->info('No failed webhook calls'.($filters->from !== null ? ' since '.$filters->from->toDateTimeString() : '').'.');
        } else {
            $this->table(
                ['ID', 'Config', 'Event', 'Status', 'Received', 'Error'],
                array_map(fn (array $r): array => [
                    count($console->sources()) > 1 ? "{$r['table']}#{$r['id']}" : $r['id'],
                    $r['name'],
                    $r['event'] ?? '',
                    Status::from($r['status'])->label(),
                    $r['received_at'],
                    Str::limit($r['error'], 80),
                ], $rows),
            );
            $this->line(sprintf('  %d failed %s. Replay them with: php artisan webhooks:replay --failed', count($rows), count($rows) === 1 ? 'call' : 'calls'));
        }

        if ($problem !== null && ! $this->option('json')) {
            $this->components->warn("Queue failures not included: {$problem}");
        }

        return $this->option('strict') && $rows !== [] ? self::FAILURE : self::SUCCESS;
    }
}

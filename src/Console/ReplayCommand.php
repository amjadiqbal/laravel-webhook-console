<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole\Console;

use AmjadIqbal\WebhookConsole\Filters;
use AmjadIqbal\WebhookConsole\Replayer;
use AmjadIqbal\WebhookConsole\ReplayResult;
use AmjadIqbal\WebhookConsole\WebhookConsole;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\WebhookClient\Models\WebhookCall;

final class ReplayCommand extends Command
{
    protected $signature = 'webhooks:replay
        {ids?* : IDs of the webhook calls to replay}
        {--failed : Replay every failed call (stored exception or failed queue job)}
        {--name= : With --failed: only calls of this webhook-client config}
        {--since= : With --failed: only calls received since then: 30m, 2h, 7d, 1w or a date}
        {--limit= : With --failed: the most calls to replay (default: webhook-console.bulk_limit)}
        {--source= : The table to read, when your configs use models with different tables}
        {--dry-run : List the calls that would be replayed, and replay nothing}
        {--force : Do not ask for confirmation}';

    protected $description = 'Dispatch the configured process job again for stored webhook calls';

    public function handle(WebhookConsole $console, Replayer $replayer): int
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter(array_map('strval', (array) $this->argument('ids')), fn (string $id): bool => $id !== ''));

        if ($ids === [] && ! $this->option('failed')) {
            $this->components->error('Pass the IDs of the calls to replay, or --failed to replay every failed call.');

            return self::INVALID;
        }

        $since = $this->option('since');

        if (is_string($since) && $since !== '' && Filters::date($since) === null) {
            $this->components->error("Could not understand --since={$since}. Use 30m, 2h, 7d, 1w or a date.");

            return self::INVALID;
        }

        $source = $console->source(is_string($this->option('source')) ? $this->option('source') : null);
        $calls = $ids !== [] ? $this->byIds($console, $source, $ids) : $this->failed($console, $source);

        if ($calls->isEmpty()) {
            $this->components->info('Nothing to replay.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Config', 'Event', 'Status', 'Received'], $calls->map(fn (WebhookCall $call): array => [
            $call->getKey(), $call->name, $console->event($call) ?? '', $console->status($call)->label(), $call->created_at?->toDateTimeString(),
        ])->all());

        if ($this->option('dry-run')) {
            $this->components->info("Dry run: {$calls->count()} ".($calls->count() === 1 ? 'call' : 'calls').' would be replayed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Dispatch the process job again for {$calls->count()} ".($calls->count() === 1 ? 'call' : 'calls').'?')) {
            $this->components->warn('Nothing replayed. Pass --force to skip this question (non-interactive runs need it).');

            return self::FAILURE;
        }

        $results = $replayer->replayMany($calls);

        foreach ($results as $result) {
            $line = "#{$result->call->getKey()} {$result->call->name}: {$result->message}";
            $result->ok() ? $this->components->info($line) : $this->components->error($line);
        }

        $tally = ReplayResult::tally($results);
        $this->line(sprintf('  %d replayed, %d failed to dispatch, %d skipped.', $tally['dispatched'], $tally['failed'], $tally['skipped']));

        return $tally['failed'] + $tally['skipped'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, WebhookCall>
     */
    private function byIds(WebhookConsole $console, string $source, array $ids): Collection
    {
        $query = $console->newQuery($source);
        $found = $query->whereIn($query->getModel()->getKeyName(), $ids)->get()->keyBy(fn (WebhookCall $c): string => (string) $c->getKey());

        foreach (array_diff($ids, $found->keys()->all()) as $missing) {
            $this->components->warn("No webhook call #{$missing} in {$source}.");
        }

        return $found->values()->toBase();
    }

    /**
     * @return Collection<int, WebhookCall>
     */
    private function failed(WebhookConsole $console, string $source): Collection
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : (int) config('webhook-console.bulk_limit', 100);
        $filters = new Filters(
            name: is_string($this->option('name')) && $this->option('name') !== '' ? $this->option('name') : null,
            status: 'failures',
            from: Filters::date($this->option('since')),
        );

        return $console->query($source, $filters)->limit(max(1, $limit))->get()->toBase();
    }
}

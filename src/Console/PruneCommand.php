<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole\Console;

use AmjadIqbal\WebhookConsole\WebhookConsole;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'webhooks:prune
        {--days= : Delete calls received more than this many days ago (default: webhook-client.delete_after_days)}
        {--name= : Only calls of this webhook-client config}
        {--source= : The table to prune, when your configs use models with different tables (default: all)}
        {--pretend : Count the calls that would be deleted, and delete nothing}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete stored webhook calls older than a number of days';

    public function handle(WebhookConsole $console): int
    {
        $days = $this->option('days') ?? config('webhook-client.delete_after_days');

        if (! is_numeric($days) || (int) $days < 1) {
            $this->components->error('Pass --days=N (1 or more), or set webhook-client.delete_after_days.');

            return self::INVALID;
        }

        $days = (int) $days;
        $name = is_string($this->option('name')) && $this->option('name') !== '' ? $this->option('name') : null;
        $tables = is_string($this->option('source')) ? [$console->source($this->option('source'))] : array_keys($console->sources());
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = $console->pruneQuery($table, $days, $name)->count();
        }

        $total = array_sum($counts);
        $what = "{$total} ".($total === 1 ? 'call' : 'calls')." older than {$days} days".($name !== null ? " for '{$name}'" : '');

        if ($total === 0 || $this->option('pretend')) {
            $this->components->info(($this->option('pretend') ? 'Would delete ' : 'Nothing to delete: ').$what.'.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$what}?")) {
            $this->components->warn('Nothing deleted. Pass --force to skip this question (scheduled and non-interactive runs need it).');

            return self::FAILURE;
        }

        $deleted = 0;
        foreach ($tables as $table) {
            $deleted += $console->prune($table, $days, $name);
        }

        $this->components->info("Deleted {$deleted} ".($deleted === 1 ? 'call' : 'calls')." older than {$days} days".($name !== null ? " for '{$name}'" : '').'.');

        return self::SUCCESS;
    }
}

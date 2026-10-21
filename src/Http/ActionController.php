<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole\Http;

use AmjadIqbal\WebhookConsole\Filters;
use AmjadIqbal\WebhookConsole\Replayer;
use AmjadIqbal\WebhookConsole\ReplayResult;
use AmjadIqbal\WebhookConsole\WebhookConsole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ActionController
{
    public function replay(Request $request, WebhookConsole $console, Replayer $replayer, string $id): RedirectResponse
    {
        abort_unless(Gate::check('replayWebhookCalls', [$request]), 403);

        $source = $console->source($request->input('source') !== null ? (string) $request->input('source') : null);
        $call = $console->find($source, $id);

        abort_if($call === null, 404);

        $result = $replayer->replay($call);

        return back()->with('webhook-console.flash', [
            'type' => $result->ok() ? 'ok' : 'error',
            'message' => $result->ok() ? "Call #{$call->getKey()} replayed: {$result->message}." : "Call #{$call->getKey()} was not replayed: {$result->message}",
        ]);
    }

    public function retry(Request $request, WebhookConsole $console, Replayer $replayer): RedirectResponse
    {
        abort_unless(Gate::check('replayWebhookCalls', [$request]), 403);

        $source = $console->source($request->input('source') !== null ? (string) $request->input('source') : null);
        $limit = max(1, (int) config('webhook-console.bulk_limit', 100));
        $ids = array_values(array_filter(array_map('strval', (array) $request->input('ids', [])), fn (string $id): bool => $id !== ''));

        if ($ids !== []) {
            $query = $console->newQuery($source);
            $query->whereIn($query->getModel()->getQualifiedKeyName(), array_slice($ids, 0, $limit));
        } else {
            // "Retry failed": every failure that matches the current filters.
            $filters = Filters::fromInput([...$request->only(['name', 'from', 'to', 'q']), 'status' => 'failures']);
            $query = $console->query($source, $filters);
        }

        $results = $replayer->replayMany($query->limit($limit)->get());
        $tally = ReplayResult::tally($results);
        $problems = array_values(array_unique(array_map(
            fn (ReplayResult $r): string => $r->message,
            array_filter($results, fn (ReplayResult $r): bool => ! $r->ok()),
        )));

        $message = $results === []
            ? 'Nothing to retry.'
            : "{$tally['dispatched']} replayed, {$tally['failed']} failed to dispatch, {$tally['skipped']} skipped.";

        return back()->with('webhook-console.flash', [
            'type' => $tally['failed'] + $tally['skipped'] > 0 ? 'error' : 'ok',
            'message' => $message.($problems !== [] ? ' '.implode(' ', array_slice($problems, 0, 3)) : ''),
        ]);
    }

    public function prune(Request $request, WebhookConsole $console): RedirectResponse
    {
        abort_unless(Gate::check('pruneWebhookCalls', [$request]), 403);

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:36500'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $source = $console->source($request->input('source') !== null ? (string) $request->input('source') : null);
        $name = isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null;
        $deleted = $console->prune($source, (int) $data['days'], $name);

        return back()->with('webhook-console.flash', [
            'type' => 'ok',
            'message' => "Deleted {$deleted} ".($deleted === 1 ? 'call' : 'calls')." older than {$data['days']} days".($name !== null ? " for '{$name}'" : '').'.',
        ]);
    }
}

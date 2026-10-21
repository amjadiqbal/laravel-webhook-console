<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole\Http;

use AmjadIqbal\WebhookConsole\Filters;
use AmjadIqbal\WebhookConsole\WebhookConsole;
use AmjadIqbal\WebhookConsole\WebhookConsoleServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DashboardController
{
    public function index(Request $request, WebhookConsole $console, Factory $views): View
    {
        $source = $console->source($request->query('source') !== null ? (string) $request->query('source') : null);
        $filters = Filters::fromInput($request->query());
        $perPage = max(1, (int) config('webhook-console.dashboard.per_page', 50));

        $query = $console->query($source, $filters);
        // Everything but attachments, which hold whole uploaded files as base64.
        $calls = $query
            ->select(array_map(fn (string $c): string => $query->getModel()->qualifyColumn($c), [$query->getModel()->getKeyName(), 'name', 'url', 'payload', 'exception', 'created_at', 'updated_at']))
            ->paginate($perPage)
            ->withQueryString();

        return $views->make('webhook-console::index', [
            ...$this->shared($request, $console, $source),
            'filters' => $filters,
            'calls' => $calls,
            'names' => $console->names($source),
            'stats' => $console->stats($source),
            'failedJobsProblem' => $console->failedJobsProblem(),
            'bulkLimit' => max(1, (int) config('webhook-console.bulk_limit', 100)),
        ]);
    }

    public function show(Request $request, WebhookConsole $console, Factory $views, string $id): View
    {
        $source = $console->source($request->query('source') !== null ? (string) $request->query('source') : null);
        $call = $console->find($source, $id);

        abort_if($call === null, 404);

        $endpoint = $console->endpoint((string) $call->name);
        $masker = $console->masker();
        $queueFailure = $console->queueFailure($call);

        return $views->make('webhook-console::show', [
            ...$this->shared($request, $console, $source),
            'call' => $call,
            'status' => $console->status($call),
            'endpoint' => $endpoint,
            'replayProblem' => $endpoint === null ? "No webhook-client config is named '{$call->name}' any more." : $endpoint->replayProblem(),
            'url' => $masker->url((string) $call->url),
            'headers' => $masker->headers($call->headers),
            'payload' => $masker->payload($call->payload),
            'attachments' => $masker->attachments(array_key_exists('attachments', $call->getAttributes()) ? $call->attachments : null),
            'queueFailure' => $queueFailure,
            'queueFailureActive' => $queueFailure !== null && $console->activeQueueFailure($call) !== null,
            'failedJobsProblem' => $console->failedJobsProblem(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(Request $request, WebhookConsole $console, string $source): array
    {
        return [
            'console' => $console,
            'source' => $source,
            'sources' => array_keys($console->sources()),
            'canReplay' => Gate::check('replayWebhookCalls', [$request]),
            'canPrune' => Gate::check('pruneWebhookCalls', [$request]),
            'version' => WebhookConsoleServiceProvider::version(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole;

use Exception;
use RuntimeException;
use Spatie\WebhookClient\Models\WebhookCall;
use Throwable;

/**
 * Re-dispatches a stored call's process job exactly as webhook-client's WebhookProcessor does
 * for a new webhook: the job from the call's config, clearException(), dispatch(), and
 * saveException() when dispatching throws. Nothing about the original request is re-sent.
 */
final class Replayer
{
    public function __construct(private readonly WebhookConsole $console) {}

    public function replay(WebhookCall $call): ReplayResult
    {
        $endpoint = $this->console->endpoint((string) $call->name);

        if ($endpoint === null) {
            return ReplayResult::skipped($call, "No webhook-client config is named '{$call->name}' any more.");
        }

        if (($problem = $endpoint->replayProblem()) !== null) {
            return ReplayResult::skipped($call, $problem);
        }

        // Load the row through the config's own webhook_model, so the job gets the class it type-hints.
        if (! $call instanceof $endpoint->modelClass) {
            $call = $endpoint->modelClass::query()->find($call->getKey()) ?? $call;
        }

        try {
            $job = new $endpoint->jobClass($call);

            $call->clearException();
            // Marks the replay: a failed_jobs row older than this no longer counts against the call.
            $call->touch();

            $pending = dispatch($job);
            unset($pending); // PendingDispatch dispatches when destroyed; do it inside the try.
        } catch (Throwable $e) {
            $call->saveException($e instanceof Exception ? $e : new RuntimeException($e->getMessage(), (int) $e->getCode(), $e));

            return ReplayResult::failed($call, $e::class.': '.$e->getMessage());
        }

        return ReplayResult::dispatched($call, $endpoint->jobClass);
    }

    /**
     * Oldest first, so the jobs see events in the order they arrived.
     *
     * @param  iterable<WebhookCall>  $calls
     * @return list<ReplayResult>
     */
    public function replayMany(iterable $calls): array
    {
        $calls = collect($calls)->sortBy(fn (WebhookCall $c): string => str_pad((string) $c->getKey(), 20, '0', STR_PAD_LEFT));
        $results = [];

        foreach ($calls as $call) {
            $results[] = $this->replay($call);
        }

        return $results;
    }
}

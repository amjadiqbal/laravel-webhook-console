<?php

declare(strict_types=1);

namespace AmjadIqbal\WebhookConsole\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::check('viewWebhookConsole', [$request]), 403);

        return $next($request);
    }

    /**
     * Whether the request may use a write action (replay or prune): the action's gate must allow it
     * and, unless dashboard.actions_require_user is false, someone must be signed in.
     */
    public static function allowsAction(Request $request, string $gate): bool
    {
        if (config('webhook-console.dashboard.actions_require_user', true) && $request->user() === null) {
            return false;
        }

        return Gate::check($gate, [$request]);
    }
}

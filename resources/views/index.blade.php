@extends('webhook-console::layout')

@section('title', 'Webhook Console')

@section('content')
    @php $base = count($sources) > 1 ? ['source' => $source] : []; @endphp

    <div class="summary">
        <a class="stat" href="{{ route('webhook-console.index', $base) }}"><div class="n">{{ number_format($stats['total']) }}</div><div class="l">Calls stored</div></a>
        <a class="stat critical" href="{{ route('webhook-console.index', [...$base, 'status' => 'failed']) }}"><div class="n">{{ number_format($stats['failed']) }}</div><div class="l">Failed (stored exception)</div></a>
        <a class="stat queue" href="{{ route('webhook-console.index', [...$base, 'status' => 'queue_failed']) }}"><div class="n">{{ $failedJobsProblem ? '–' : number_format($stats['queue_failed']) }}</div><div class="l">Failed in queue</div></a>
        <div class="stat"><div class="n">{{ number_format($stats['last_day']) }}</div><div class="l">Last 24 hours</div></div>
    </div>

    @if ($failedJobsProblem)
        <div class="note">Queue failures are not shown: {{ $failedJobsProblem }} Calls whose job threw on a queue worker look like "No error" here.</div>
    @elseif ($console->failedJobsLimited())
        <div class="note">failed_jobs holds more than {{ number_format((int) config('webhook-console.failed_jobs.scan_limit', 1000)) }} rows and only the newest were checked: older queue failures show as "No error". Raise failed_jobs.scan_limit, or clear old rows with <code>php artisan queue:prune-failed</code>.</div>
    @endif

    @if ($filters->search !== null && $console->searchWasLimited($source))
        <div class="note">Only the newest matches were checked, so older calls may be missing from this search. Narrow it with a config, status or date filter.</div>
    @endif

    <form class="filters" method="get" action="{{ route('webhook-console.index') }}">
        @if (count($sources) > 1)<input type="hidden" name="source" value="{{ $source }}">@endif
        <label>Config
            <select name="name">
                <option value="">All</option>
                @foreach ($names as $name => $count)
                    <option value="{{ $name }}" @selected($filters->name === $name)>{{ $name }} ({{ number_format($count) }})</option>
                @endforeach
            </select>
        </label>
        <label>Status
            <select name="status">
                @foreach (['' => 'All', 'failures' => 'Any failure', 'failed' => 'Failed (stored exception)', 'queue_failed' => 'Failed in queue', 'ok' => 'No error'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>From <input type="date" name="from" value="{{ $filters->from?->format('Y-m-d') }}"></label>
        <label>To <input type="date" name="to" value="{{ $filters->to?->format('Y-m-d') }}"></label>
        <label>Search payload or URL <input type="search" name="q" value="{{ $filters->search }}" placeholder="event id, email, order…"></label>
        <button class="btn primary" type="submit">Filter</button>
        @if ($filters->toQuery() !== [])
            <a class="btn" href="{{ route('webhook-console.index', $base) }}">Clear</a>
        @endif
    </form>

    <form method="post" action="{{ route('webhook-console.retry') }}" id="bulk">
        @csrf
        @if (count($sources) > 1)<input type="hidden" name="source" value="{{ $source }}">@endif
        @foreach ($filters->toQuery() as $key => $value)
            @if ($key !== 'status')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach

        <div class="bulk">
            <div class="left">
                @if ($canReplay)
                    <button class="btn" type="submit" id="retry-selected" disabled
                            onclick="return confirm('Replay the selected calls? Their process jobs are dispatched again.')">Replay selected</button>
                    <button class="btn primary" type="submit" name="all" value="1"
                            onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(function (c) { c.checked = false; }); return confirm('Replay up to {{ $bulkLimit }} failed calls matching these filters?')">Retry failed</button>
                @else
                    <span class="muted" style="font-size:13.5px">Read-only: replay needs a signed-in user whom the replayWebhookCalls gate allows.</span>
                @endif
            </div>
            <span class="muted" style="font-size:13.5px">{{ number_format($calls->total()) }} {{ $calls->total() === 1 ? 'call' : 'calls' }}</span>
        </div>

        <div class="table-wrap">
            @if ($calls->isEmpty())
                <div class="empty">No webhook calls match.</div>
            @else
                <table class="calls">
                    <thead>
                    <tr>
                        @if ($canReplay)<th><input type="checkbox" aria-label="Select all" onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(function (c) { c.checked = this.checked; }, this); window.syncBulk()"></th>@endif
                        <th>#</th>
                        <th>Config</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th class="hide-sm">Error</th>
                        <th>Received</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($calls as $call)
                        @php
                            $status = $console->status($call);
                            $error = match ($status) {
                                \AmjadIqbal\WebhookConsole\Status::Failed => is_array($call->exception) ? $console->maskText($call, (string) ($call->exception['message'] ?? '')) : '',
                                \AmjadIqbal\WebhookConsole\Status::QueueFailed => $console->maskText($call, $console->activeQueueFailure($call)?->message() ?? ''),
                                default => '',
                            };
                            $link = route('webhook-console.show', ['id' => $call->getKey(), ...$base]);
                        @endphp
                        <tr>
                            @if ($canReplay)<td><input type="checkbox" name="ids[]" value="{{ $call->getKey() }}" aria-label="Select call {{ $call->getKey() }}" onchange="window.syncBulk()"></td>@endif
                            <td><a href="{{ $link }}">{{ $call->getKey() }}</a></td>
                            <td><span class="chip">{{ $call->name }}</span></td>
                            <td class="mono" style="font-size:13px">{{ $console->event($call) ?? '—' }}</td>
                            <td><span class="badge {{ $status->value }}">{{ $status->label() }}</span></td>
                            <td class="err hide-sm">{{ \Illuminate\Support\Str::limit($error, 140) }}</td>
                            <td class="when" title="{{ $call->created_at }}"><a href="{{ $link }}" style="color:inherit">{{ $call->created_at?->diffForHumans() }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </form>

    @if ($calls->hasPages())
        <div class="pager">
            <span>Page {{ $calls->currentPage() }} of {{ $calls->lastPage() }}</span>
            <span>
                @if ($calls->previousPageUrl())<a class="btn" href="{{ $calls->previousPageUrl() }}">Newer</a>@endif
                @if ($calls->nextPageUrl())<a class="btn" href="{{ $calls->nextPageUrl() }}">Older</a>@endif
            </span>
        </div>
    @endif

    @if ($canPrune)
        <details class="panel" style="margin-top:22px">
            <summary>Prune old calls</summary>
            <form method="post" action="{{ route('webhook-console.prune') }}" class="filters" style="margin:12px 0 0"
                  onsubmit="return confirm('Permanently delete these webhook calls? This cannot be undone.')">
                @csrf
                @if (count($sources) > 1)<input type="hidden" name="source" value="{{ $source }}">@endif
                <label>Older than (days) <input type="number" name="days" min="1" value="{{ (int) config('webhook-client.delete_after_days', 30) ?: 30 }}" required style="width:110px"></label>
                <label>Config
                    <select name="name">
                        <option value="">All</option>
                        @foreach ($names as $name => $count)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="btn danger" type="submit">Delete</button>
            </form>
            <p class="muted" style="font-size:13px;margin:10px 0 0">webhook-client can also do this on a schedule: <code>php artisan model:prune --model="{{ \Spatie\WebhookClient\Models\WebhookCall::class }}"</code> uses <code>delete_after_days</code>.</p>
        </details>
    @endif

    <script>
        window.syncBulk = function () {
            var button = document.getElementById('retry-selected');
            if (button) { button.disabled = document.querySelectorAll('input[name="ids[]"]:checked').length === 0; }
        };
    </script>
@endsection

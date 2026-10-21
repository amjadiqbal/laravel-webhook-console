@extends('webhook-console::layout')

@section('title', 'Webhook #'.$call->getKey().' · Webhook Console')

@section('content')
    @php $base = count($sources) > 1 ? ['source' => $source] : []; @endphp

    <p style="margin:0 0 14px"><a href="{{ url()->previous() !== url()->current() && str_contains(url()->previous(), route('webhook-console.index')) ? url()->previous() : route('webhook-console.index', $base) }}">← All calls</a></p>

    <div class="panel">
        <div class="ph">
            <h2 style="font-size:18px">Call #{{ $call->getKey() }} <span class="badge {{ $status->value }}" style="vertical-align:3px;margin-left:6px">{{ $status->label() }}</span></h2>
            @if ($canReplay)
                @if ($replayProblem)
                    <button class="btn" disabled title="{{ $replayProblem }}">Replay</button>
                @else
                    <form method="post" action="{{ route('webhook-console.replay', ['id' => $call->getKey()]) }}"
                          onsubmit="return confirm('Dispatch {{ class_basename($endpoint->jobClass) }} again for this call?')">
                        @csrf
                        @if (count($sources) > 1)<input type="hidden" name="source" value="{{ $source }}">@endif
                        <button class="btn primary" type="submit">Replay</button>
                    </form>
                @endif
            @endif
        </div>
        <dl class="kv">
            <dt>Config</dt><dd><span class="chip">{{ $call->name }}</span>@if (! $endpoint) <span class="muted">(not in config/webhook-client.php any more)</span>@endif</dd>
            <dt>Event</dt><dd class="mono">{{ $console->event($call) ?? '—' }}</dd>
            <dt>URL</dt><dd class="mono">{{ $url }}</dd>
            <dt>Received</dt><dd>{{ $call->created_at }} <span class="muted">({{ $call->created_at?->diffForHumans() }})</span></dd>
            <dt>Updated</dt><dd>{{ $call->updated_at }}</dd>
            @if ($endpoint)
                <dt>Process job</dt><dd class="mono">{{ $endpoint->jobClass ?: '—' }}</dd>
                <dt>Model</dt><dd class="mono">{{ $endpoint->modelClass }}</dd>
            @endif
            @if ($canReplay && $replayProblem)
                <dt>Replay</dt><dd style="color:var(--critical)">{{ $replayProblem }}</dd>
            @endif
        </dl>
    </div>

    @if (is_array($call->exception))
        <div class="panel" style="border-left:4px solid var(--critical)">
            <div class="ph"><h2>Stored exception</h2><span class="muted" style="font-size:13px">code {{ $call->exception['code'] ?? 0 }}</span></div>
            <p style="margin:0 0 10px;font-weight:600;word-break:break-word">{{ $console->maskText($call, (string) ($call->exception['message'] ?? '')) }}</p>
            @if (! empty($call->exception['trace']))
                <details><summary>Stack trace</summary><pre class="code wrap-lines" style="margin-top:8px">{{ $console->maskText($call, (string) $call->exception['trace']) }}</pre></details>
            @endif
        </div>
    @endif

    @if ($queueFailure)
        <div class="panel" style="border-left:4px solid {{ $queueFailureActive ? 'var(--queue)' : 'var(--border)' }}">
            <div class="ph">
                <h2>{{ $queueFailureActive ? 'Failed in queue' : 'Earlier queue failure (replayed since)' }}</h2>
                <span class="muted" style="font-size:13px">{{ $queueFailure->failedAt }} · {{ $queueFailure->connection }}/{{ $queueFailure->queue }}</span>
            </div>
            <p style="margin:0 0 10px;font-weight:600;word-break:break-word">{{ $console->maskText($call, $queueFailure->message()) }}</p>
            <details><summary>Exception from failed_jobs <span class="mono">{{ $queueFailure->id }}</span></summary><pre class="code wrap-lines" style="margin-top:8px">{{ $console->maskText($call, $queueFailure->exception) }}</pre></details>
        </div>
    @elseif ($failedJobsProblem)
        <div class="note">failed_jobs is not read: {{ $failedJobsProblem }}</div>
    @elseif ($console->failedJobsLimited())
        <div class="note">Only the newest {{ number_format((int) config('webhook-console.failed_jobs.scan_limit', 1000)) }} failed_jobs rows were checked, so an older queue failure of this call would not show here. Raise failed_jobs.scan_limit, or clear old rows with <code>php artisan queue:prune-failed</code>.</div>
    @endif

    <div class="grid2">
        <div class="panel">
            <div class="ph"><h2>Payload</h2><button class="copy" type="button" data-copy="payload-json">Copy</button></div>
            <pre class="code" id="payload-json">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
        </div>
        <div class="panel">
            <div class="ph"><h2>Headers</h2><span class="muted" style="font-size:13px">{{ count($headers) }} stored</span></div>
            @if ($headers === [])
                <p class="muted" style="margin:0;font-size:13.5px">No headers stored. webhook-client keeps only the names listed in <code>store_headers</code> (or every header with <code>'*'</code>).</p>
            @else
                <table class="headers">
                    @foreach ($headers as $name => $values)
                        <tr><td class="mono">{{ $name }}</td><td class="mono">{{ implode(', ', $values) }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if ($attachments !== [])
                <h2 style="margin-top:18px">Attachments</h2>
                <table class="headers">
                    @foreach ($attachments as $file)
                        <tr><td class="mono">{{ $file['name'] }}</td><td>{{ $file['type'] }} · {{ number_format($file['size']) }} bytes</td></tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>
@endsection

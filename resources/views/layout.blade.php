<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Webhook Console')</title>
    <style>
        :root {
            --bg: #F8FAFC; --panel: #FFFFFF; --border: #E2E8F0; --text: #0F172A; --muted: #64748B; --soft: #F1F5F9;
            --accent: #0E7490; --accent-text: #0E7490; --on-accent: #FFFFFF; --code-bg: #0F172A; --code-text: #E2E8F0; --code-key: #67E8F9;
            --critical: #DC2626; --critical-bg: #FEE2E2; --queue: #7C3AED; --queue-bg: #EDE9FE; --ok: #16A34A; --ok-bg: #DCFCE7;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0B1220; --panel: #111C33; --border: #24324F; --text: #F8FAFC; --muted: #94A3B8; --soft: #16213A;
                --accent: #22D3EE; --accent-text: #67E8F9; --on-accent: #0B1220; --code-bg: #0B1220; --code-text: #E2E8F0; --code-key: #67E8F9;
                /* Solid colours only: on dark, translucent warm fills read as brown. Badges use a neutral fill and coloured text. */
                --critical: #F87171; --critical-bg: #16213A; --queue: #C4B5FD; --queue-bg: #16213A; --ok: #4ADE80; --ok-bg: #16213A;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; }
        a { color: var(--accent-text); }
        code, pre, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 28px 16px 56px; }
        header.top { display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 12px; color: inherit; text-decoration: none; }
        .brand h1 { font-size: 22px; margin: 0; letter-spacing: -0.01em; }
        .sub { color: var(--muted); font-size: 13.5px; }
        .sub b { color: var(--text); font-weight: 600; }
        .btn { display: inline-block; padding: 7px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--text); text-decoration: none; font: inherit; font-size: 14px; cursor: pointer; }
        .btn:hover { border-color: var(--accent); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); font-weight: 600; }
        .btn.danger { color: var(--critical); }
        .btn[disabled] { opacity: .5; cursor: not-allowed; }
        .summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
        .stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; color: inherit; text-decoration: none; }
        a.stat:hover { border-color: var(--accent); }
        .stat .n { font-size: 26px; font-weight: 700; line-height: 1.1; }
        .stat .l { color: var(--muted); font-size: 13px; }
        .stat.critical .n { color: var(--critical); } .stat.queue .n { color: var(--queue); }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
        .panel h2 { font-size: 15px; margin: 0 0 10px; }
        .flash { border: 1px solid var(--border); border-left: 4px solid var(--ok); background: var(--panel); border-radius: 10px; padding: 11px 14px; margin-bottom: 16px; font-size: 14px; }
        .flash.error { border-left-color: var(--critical); }
        .note { border: 1px solid var(--border); border-left: 4px solid var(--accent); background: var(--panel); border-radius: 10px; padding: 11px 14px; margin-bottom: 16px; font-size: 13.5px; color: var(--muted); }
        form.filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 14px; }
        form.filters label { display: flex; flex-direction: column; gap: 3px; font-size: 12.5px; color: var(--muted); }
        input, select { font: inherit; font-size: 14px; padding: 6px 9px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--text); min-width: 0; }
        input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 0; }
        .table-wrap { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; overflow-x: auto; }
        table.calls { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.calls th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        table.calls td { padding: 9px 12px; border-top: 1px solid var(--border); vertical-align: top; }
        table.calls tr:first-child td { border-top: 0; }
        table.calls tr:hover td { background: var(--soft); }
        table.calls .err { color: var(--muted); font-size: 12.5px; word-break: break-word; max-width: 420px; }
        table.calls td.when { white-space: nowrap; color: var(--muted); font-size: 13px; }
        .badge { display: inline-block; padding: 1px 9px; border-radius: 999px; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; border: 1px solid currentColor; white-space: nowrap; }
        .badge.failed { color: var(--critical); background: var(--critical-bg); }
        .badge.queue_failed { color: var(--queue); background: var(--queue-bg); }
        .badge.ok { color: var(--ok); background: var(--ok-bg); }
        .chip { display: inline-block; font-size: 12.5px; padding: 1px 8px; border-radius: 6px; background: var(--soft); border: 1px solid var(--border); }
        .bulk { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin: 0 0 10px; }
        .bulk .left { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .empty { text-align: center; padding: 34px 16px; color: var(--muted); }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 12px; color: var(--muted); font-size: 13.5px; }
        dl.kv { display: grid; grid-template-columns: max-content 1fr; gap: 6px 18px; margin: 0; font-size: 14px; }
        dl.kv dt { color: var(--muted); }
        dl.kv dd { margin: 0; word-break: break-word; }
        pre.code { margin: 0; background: var(--code-bg); color: var(--code-text); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; overflow: auto; max-height: 560px; font-size: 13px; line-height: 1.5; white-space: pre; }
        pre.code.wrap-lines { white-space: pre-wrap; word-break: break-word; }
        .ph { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
        .ph h2 { margin: 0; }
        .copy { border: 1px solid var(--border); background: var(--soft); color: var(--text); border-radius: 6px; padding: 2px 9px; font-size: 12px; cursor: pointer; }
        .copy:hover { border-color: var(--accent); }
        table.headers { border-collapse: collapse; width: 100%; font-size: 13px; }
        table.headers td { border-top: 1px solid var(--border); padding: 5px 8px 5px 0; vertical-align: top; word-break: break-all; }
        table.headers tr:first-child td { border-top: 0; }
        table.headers td:first-child { color: var(--muted); white-space: nowrap; width: 1%; padding-right: 18px; }
        .grid2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; }
        details summary { cursor: pointer; color: var(--muted); font-size: 13.5px; }
        footer { margin-top: 34px; color: var(--muted); font-size: 13px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }
        .muted { color: var(--muted); }
        @media (max-width: 760px) { .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } .grid2 { grid-template-columns: minmax(0, 1fr); } table.calls .hide-sm { display: none; } }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <a class="brand" href="{{ route('webhook-console.index', count($sources) > 1 ? ['source' => $source] : []) }}">
            <svg width="38" height="38" viewBox="0 0 512 512" aria-hidden="true">
                <rect x="8" y="8" width="496" height="496" rx="108" fill="#0F172A" stroke="#334155" stroke-width="16"/>
                <rect x="92" y="88" width="356" height="320" rx="30" fill="#1E293B"/>
                <circle cx="136" cy="180" r="18" fill="#22C55E"/>
                <circle cx="136" cy="250" r="18" fill="#EF4444"/>
                <circle cx="136" cy="320" r="18" fill="#22C55E"/>
                <path d="M30 250 H100 M76 222 L106 250 L76 278" fill="none" stroke="#22D3EE" stroke-width="22" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="384" cy="380" r="80" fill="#0891B2" stroke="#0F172A" stroke-width="14"/>
                <path d="M422 380 a38 38 0 1 1 -11 -27" fill="none" stroke="#FFFFFF" stroke-width="20" stroke-linecap="round"/>
                <path d="M396 336 L414 354 L392 368" fill="none" stroke="#FFFFFF" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div>
                <h1>Webhook Console</h1>
                <div class="sub">spatie/laravel-webhook-client · table <b>{{ $source }}</b></div>
            </div>
        </a>
        @if (count($sources) > 1)
            <form method="get" action="{{ route('webhook-console.index') }}">
                <label class="sub">Table
                    <select name="source" onchange="this.form.submit()">
                        @foreach ($sources as $s)
                            <option value="{{ $s }}" @selected($s === $source)>{{ $s }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        @endif
    </header>

    @if (session('webhook-console.flash'))
        @php $flash = session('webhook-console.flash'); @endphp
        <div class="flash {{ $flash['type'] === 'error' ? 'error' : '' }}" role="status">{{ $flash['message'] }}</div>
    @endif

    @if ($errors->any())
        <div class="flash error" role="alert">{{ $errors->first() }}</div>
    @endif

    @yield('content')

    <footer>
        <span>laravel-webhook-console {{ $version }} · {{ $canReplay ? 'replay allowed' : 'read-only' }}</span>
        <span>Secrets in payloads, headers and URLs are masked.</span>
    </footer>
</div>
<script>
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var source = document.getElementById(button.getAttribute('data-copy'));
            var text = source ? source.textContent : '';
            var done = function (label) { var old = button.textContent; button.textContent = label; setTimeout(function () { button.textContent = old; }, 1400); };
            var select = function () {
                var range = document.createRange(); range.selectNodeContents(source);
                var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
                try { if (document.execCommand('copy')) { done('Copied'); return; } } catch (e) {}
                done('Press Ctrl+C');
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { done('Copied'); }, select);
            } else {
                select();
            }
        });
    });
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup required — .env not found</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0f172a;
            color: #1e293b;
        }
        .backdrop {
            position: fixed;
            inset: 0;
            background: radial-gradient(1200px 600px at 50% -10%, rgba(14, 150, 35, .18), transparent), #0b1220;
        }
        .modal {
            position: relative;
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .45);
            overflow: hidden;
            animation: pop .3s cubic-bezier(.4, 0, .2, 1) both;
        }
        @keyframes pop { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: none; } }
        .modal-head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 22px 26px;
            background: linear-gradient(135deg, rgba(245, 158, 11, .12), rgba(245, 158, 11, .02));
            border-bottom: 1px solid #f1f5f9;
        }
        .modal-icon {
            width: 46px;
            height: 46px;
            flex: none;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff4e0;
            color: #b45309;
        }
        .modal-icon svg { width: 24px; height: 24px; }
        .modal-title { font-size: 1.18rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
        .modal-sub { font-size: .82rem; color: #64748b; margin-top: 2px; }
        .modal-body { padding: 22px 26px 8px; }
        .modal-body p { font-size: .92rem; color: #475569; line-height: 1.55; margin-bottom: 18px; }
        .steps { list-style: none; counter-reset: step; }
        .steps li {
            position: relative;
            counter-increment: step;
            padding: 0 0 18px 42px;
            font-size: .9rem;
            color: #334155;
            line-height: 1.5;
        }
        .steps li::before {
            content: counter(step);
            position: absolute;
            left: 0;
            top: -1px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #0E9623;
            color: #fff;
            font-size: .78rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .steps li:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 12.5px;
            top: 28px;
            bottom: 4px;
            width: 1px;
            background: #e2e8f0;
        }
        .cmd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 8px;
            background: #0f172a;
            border-radius: 10px;
            padding: 10px 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .82rem;
            color: #e2e8f0;
        }
        .cmd code { color: #86efac; white-space: nowrap; overflow-x: auto; }
        .copy-btn {
            flex: none;
            border: 0;
            background: rgba(255, 255, 255, .12);
            color: #fff;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: .74rem;
            cursor: pointer;
        }
        .copy-btn:hover { background: rgba(255, 255, 255, .22); }
        .modal-foot {
            padding: 16px 26px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .hint { font-size: .78rem; color: #94a3b8; }
        .reload-btn {
            border: 0;
            border-radius: 10px;
            background: #0E9623;
            color: #fff;
            font-weight: 600;
            font-size: .9rem;
            padding: 10px 18px;
            cursor: pointer;
        }
        .reload-btn:hover { background: #0c7f1e; }
    </style>
</head>

<body>
    <div class="backdrop"></div>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="envTitle">
        <div class="modal-head">
            <span class="modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                    <line x1="12" y1="9" x2="12" y2="13" />
                    <line x1="12" y1="17" x2="12.01" y2="17" />
                </svg>
            </span>
            <div>
                <div class="modal-title" id="envTitle">Configuration file not found</div>
                <div class="modal-sub">The application needs a <b>.env</b> file to run.</div>
            </div>
        </div>

        <div class="modal-body">
            <p>This application ships without a <code>.env</code> file for security. Create one from the provided
                example, set your values, then reload this page.</p>
            <ol class="steps">
                <li>
                    Copy the example file to <b>.env</b> in the project root:
                    <div class="cmd">
                        <code id="cmd">cp .env.example .env</code>
                        <button type="button" class="copy-btn" onclick="copyCmd()">Copy</button>
                    </div>
                </li>
                <li>Open <b>.env</b> and set your database and app details (DB name, user, password, APP_URL, etc.).</li>
                <li>Reload this page — the setup / installation will continue automatically.</li>
            </ol>
        </div>

        <div class="modal-foot">
            <span class="hint">This screen disappears once a <b>.env</b> file is present.</span>
            <button type="button" class="reload-btn" onclick="location.reload()">Reload</button>
        </div>
    </div>

    <script>
        function copyCmd() {
            var t = document.getElementById('cmd').textContent;
            navigator.clipboard && navigator.clipboard.writeText(t);
            var b = document.querySelector('.copy-btn');
            if (b) { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); }
        }
    </script>
</body>

</html>

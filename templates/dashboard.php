<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <?php
    $title = isset($title) ? (string) $title : 'Mail Support Assistant';
    $projectRoot = isset($projectRoot) ? (string) $projectRoot : '';
    $toolsBaseUrl = isset($toolsBaseUrl) ? (string) $toolsBaseUrl : '';
    $imapAvailable = isset($imapAvailable) ? (bool) $imapAvailable : false;
    $toolsAdminUrl = isset($toolsAdminUrl) ? (string) $toolsAdminUrl : '';
    $config = isset($config) && is_array($config) ? $config : [];
    $configError = isset($configError) ? (string) $configError : null;
    $lastRun = isset($lastRun) && is_array($lastRun) ? $lastRun : [];
    $messageState = isset($messageState) && is_array($messageState) ? $messageState : [];
    $logLines = isset($logLines) && is_array($logLines) ? $logLines : [];
    $ajaxBase = isset($ajaxBase) ? (string) $ajaxBase : '/';
    ?>
    <title><?php echo htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background:#f8fafc; color:#0f172a; margin:0; }
        .wrap { max-width:1200px; margin:0 auto; padding:24px; }
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px; }
        .card { background:#fff; border:1px solid #cbd5e1; border-radius:14px; padding:20px; box-shadow:0 12px 30px rgba(15,23,42,.06); }
        .btn { display:inline-block; padding:10px 14px; border-radius:10px; background:#2563eb; color:#fff; text-decoration:none; font-weight:700; }
        .btn.secondary { background:#0f766e; }
        .btn.mutedbtn { background:#475569; }
        .muted { color:#475569; }
        pre { white-space:pre-wrap; word-break:break-word; background:#0f172a; color:#e2e8f0; padding:14px; border-radius:10px; overflow:auto; }
        code { background:#e2e8f0; padding:2px 5px; border-radius:6px; }
        .error { color:#991b1b; }
        .toolbar { display:flex; gap:10px; flex-wrap:wrap; margin:16px 0 20px; }
        .statusline { margin-top:10px; font-size:.95rem; color:#0f172a; }
    </style>
</head>
<body>
<div class="wrap">
    <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:center; margin-bottom:20px;">
        <div>
            <h1 style="margin:0;"><?php echo htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="muted">Standalone dashboard for the PHP mail assistant client.</div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <?php if (!empty($toolsAdminUrl)): ?>
                <a class="btn" href="<?php echo htmlspecialchars((string) $toolsAdminUrl, ENT_QUOTES, 'UTF-8'); ?>">Open Tools admin</a>
            <?php endif; ?>
            <a class="btn mutedbtn" href="/logout">Log out</a>
        </div>
    </div>

    <div class="toolbar">
        <button class="btn secondary" type="button" data-action="refresh">Refresh dashboard</button>
        <button class="btn" type="button" data-action="self-test">Run self-test</button>
        <button class="btn" type="button" data-action="run-dry">Run dry-run now</button>
        <button class="btn" type="button" style="background:#b91c1c;" data-action="open-cleanup">🗑 Cleanup storage</button>
    </div>
    <div class="statusline" id="ajax-status">Ready.</div>

    <!-- Cleanup modal -->
    <div id="cleanup-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:14px; padding:28px; max-width:400px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,.3);">
            <h3 style="margin-top:0;">Cleanup storage</h3>
            <p class="muted" style="font-size:.9rem;">Choose what to purge. This cannot be undone.</p>
            <label style="display:block; margin-bottom:8px;"><input type="checkbox" id="cleanup-log" checked> Log file</label>
            <label style="display:block; margin-bottom:8px;"><input type="checkbox" id="cleanup-last-run" checked> Last run summary</label>
            <label style="display:block; margin-bottom:8px;"><input type="checkbox" id="cleanup-state" checked> Message history / state</label>
            <label style="display:block; margin-bottom:16px;"><input type="checkbox" id="cleanup-copies"> Saved message copies</label>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button class="btn mutedbtn" type="button" id="cleanup-cancel">Cancel</button>
                <button class="btn" type="button" style="background:#b91c1c;" id="cleanup-confirm">Purge selected</button>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2 style="margin-top:0;">Runtime status</h2>
            <ul>
                <li>Project root: <code><?php echo htmlspecialchars((string) $projectRoot, ENT_QUOTES, 'UTF-8'); ?></code></li>
                <li>Tools API: <code><?php echo htmlspecialchars((string) $toolsBaseUrl, ENT_QUOTES, 'UTF-8'); ?></code></li>
                <li>IMAP extension: <strong><?php echo $imapAvailable ? 'available' : 'missing'; ?></strong></li>
            </ul>
            <?php if (!empty($configError)): ?>
                <p class="error"><strong>Config fetch:</strong> <?php echo htmlspecialchars((string) $configError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <p class="muted">Config fetch succeeded. Mailboxes returned: <strong><?php echo count((array) ($config['mailboxes'] ?? [])); ?></strong></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Last run summary</h2>
            <p class="muted">Look under each mailbox's <code>message_results</code> list for the current per-message outcome of the latest run.</p>
            <?php if (!empty($lastRun)): ?>
                <pre id="last-run-panel"><?php echo htmlspecialchars(json_encode($lastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
            <?php else: ?>
                <pre id="last-run-panel">No local run summary has been saved yet. Run <code>php run --dry-run</code> or use the dry-run button above.</pre>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid" style="margin-top:16px;">
        <div class="card">
            <h2 style="margin-top:0;">Local message history</h2>
            <p class="muted">This file is diagnostic history only. Unread IMAP messages may still be re-evaluated on later runs even if they already appear here.</p>
            <?php if (!empty($messageState)): ?>
                <pre id="message-state-panel"><?php echo htmlspecialchars(json_encode($messageState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
            <?php else: ?>
                <pre id="message-state-panel">No local message history has been recorded yet.</pre>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Fetched config preview</h2>
            <?php if (!empty($config)): ?>
                <pre id="config-panel"><?php echo htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
            <?php else: ?>
                <pre id="config-panel">No config preview available.</pre>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Recent local logs</h2>
            <?php if (!empty($logLines)): ?>
                <pre id="log-panel"><?php echo htmlspecialchars(implode("\n", $logLines), ENT_QUOTES, 'UTF-8'); ?></pre>
            <?php else: ?>
                <pre id="log-panel">No logs yet.</pre>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(() => {
    const ajaxBase = <?php echo json_encode($ajaxBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const statusNode = document.getElementById('ajax-status');
    const configPanel = document.getElementById('config-panel');
    const lastRunPanel = document.getElementById('last-run-panel');
    const messageStatePanel = document.getElementById('message-state-panel');
    const logPanel = document.getElementById('log-panel');

    const setStatus = (message, isError = false) => {
        if (!statusNode) {
            return;
        }
        statusNode.textContent = message;
        statusNode.style.color = isError ? '#991b1b' : '#0f172a';
    };

    const pretty = (value) => {
        if (typeof value === 'string') {
            return value;
        }
        return JSON.stringify(value, null, 2);
    };

    const applyDashboard = (payload) => {
        const data = payload?.data ?? payload;
        if (configPanel) {
            configPanel.textContent = pretty(data?.config ?? 'No config preview available.');
        }
        if (lastRunPanel) {
            lastRunPanel.textContent = pretty(data?.lastRun ?? 'No local run summary has been saved yet.');
        }
        if (messageStatePanel) {
            messageStatePanel.textContent = pretty(data?.messageState ?? 'No local message history has been recorded yet.');
        }
        if (logPanel) {
            logPanel.textContent = Array.isArray(data?.logLines) && data.logLines.length
                ? data.logLines.join('\n')
                : 'No logs yet.';
        }
    };

    const request = async (action, options = {}) => {
        const method = options.method || 'GET';
        const body = options.body || null;
        const response = await fetch(`${ajaxBase}?ajax=${encodeURIComponent(action)}`, {
            method,
            headers: body ? { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json' } : { 'Accept': 'application/json' },
            body,
            credentials: 'same-origin'
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || `Request failed (${response.status})`);
        }
        return payload;
    };

    document.querySelectorAll('[data-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const action = button.getAttribute('data-action');
            if (!action) return;

            if (action === 'open-cleanup') {
                const modal = document.getElementById('cleanup-modal');
                if (modal) modal.style.display = 'flex';
                return;
            }

            button.disabled = true;
            setStatus(`Running ${action}...`);
            try {
                const payload = await request(action === 'run-dry' ? 'run-dry' : action, action === 'run-dry' ? {
                    method: 'POST',
                    body: 'limit=10'
                } : {});
                applyDashboard(payload.data ?? payload);
                if (payload.result) {
                    lastRunPanel.textContent = pretty(payload.result);
                }
                setStatus(action === 'run-dry' ? 'Dry-run completed.' : `${action} completed.`);
            } catch (error) {
                setStatus(error instanceof Error ? error.message : 'Ajax request failed.', true);
            } finally {
                button.disabled = false;
            }
        });
    });

    // Cleanup modal
    const cleanupModal = document.getElementById('cleanup-modal');
    const cleanupCancel = document.getElementById('cleanup-cancel');
    const cleanupConfirm = document.getElementById('cleanup-confirm');

    if (cleanupCancel) {
        cleanupCancel.addEventListener('click', () => {
            if (cleanupModal) cleanupModal.style.display = 'none';
        });
    }

    if (cleanupConfirm) {
        cleanupConfirm.addEventListener('click', async () => {
            cleanupConfirm.disabled = true;
            setStatus('Purging storage...');
            if (cleanupModal) cleanupModal.style.display = 'none';

            const log      = document.getElementById('cleanup-log')?.checked ? '1' : '0';
            const last_run = document.getElementById('cleanup-last-run')?.checked ? '1' : '0';
            const state    = document.getElementById('cleanup-state')?.checked ? '1' : '0';
            const copies   = document.getElementById('cleanup-copies')?.checked ? '1' : '0';
            const body = `log=${log}&last_run=${last_run}&state=${state}&copies=${copies}`;

            try {
                const payload = await request('cleanup', { method: 'POST', body });
                applyDashboard(payload.data ?? payload);
                const purged = payload.result?.purged ?? [];
                setStatus('Cleanup done. Purged: ' + (purged.length ? purged.join(', ') : 'nothing'));
            } catch (error) {
                setStatus(error instanceof Error ? error.message : 'Cleanup failed.', true);
            } finally {
                cleanupConfirm.disabled = false;
            }
        });
    }
})();
</script>
</body>
</html>




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
        :root {
            --bg: #f8fafc;
            --panel: #ffffff;
            --border: #cbd5e1;
            --text: #0f172a;
            --muted: #475569;
            --primary: #2563eb;
            --secondary: #0f766e;
            --danger: #b91c1c;
            --warning: #b45309;
            --success: #047857;
            --shadow: 0 12px 30px rgba(15,23,42,.06);
        }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background:var(--bg); color:var(--text); margin:0; }
        .wrap { max-width: 1380px; margin: 0 auto; padding: 24px; }
        .hero { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:center; margin-bottom:20px; }
        .hero p { margin:.35rem 0 0; color:var(--muted); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 14px; border:none; border-radius:10px; background:var(--primary); color:#fff; text-decoration:none; font-weight:700; cursor:pointer; }
        .btn.secondary { background:var(--secondary); }
        .btn.mutedbtn { background:#475569; }
        .toolbar { display:flex; gap:10px; flex-wrap:wrap; margin:16px 0 20px; }
        .statusline { margin-top:10px; font-size:.95rem; color:var(--text); }
        .summary-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px; }
        .summary-card, .panel, .mailbox-card, .message-card, .history-card, .config-card, .log-card { background:var(--panel); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow); }
        .summary-card { padding:16px; }
        .summary-card .label { color:var(--muted); font-size:.88rem; }
        .summary-card .value { font-size:1.9rem; font-weight:800; margin:.25rem 0; }
        .summary-card .note { color:var(--muted); font-size:.88rem; }
        .tone-primary .value { color:var(--primary); }
        .tone-success .value { color:var(--success); }
        .tone-warning .value { color:var(--warning); }
        .tone-danger .value { color:var(--danger); }
        .panel { padding:18px; }
        .tabs { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; }
        .tab-btn { border:1px solid var(--border); background:#e2e8f0; color:var(--text); border-radius:999px; padding:10px 14px; cursor:pointer; font-weight:700; }
        .tab-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        .mailbox-list, .history-list, .config-list, .log-list { display:grid; gap:16px; }
        .mailbox-card, .history-card, .config-card, .log-card { padding:18px; }
        .mailbox-head, .history-head, .config-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
        .stats-row, .pill-row { display:flex; gap:8px; flex-wrap:wrap; }
        .stat-pill, .pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
        .stat-pill { background:#e0f2fe; color:#1d4ed8; }
        .pill-muted { background:#e2e8f0; color:#334155; }
        .pill-success { background:#dcfce7; color:#166534; }
        .pill-warning { background:#fef3c7; color:#92400e; }
        .pill-danger { background:#fee2e2; color:#991b1b; }
        .message-list { display:grid; gap:14px; }
        .message-card { padding:16px; }
        .message-top { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; }
        .message-subject { font-size:1.05rem; font-weight:800; margin:0 0 6px; }
        .message-meta { color:var(--muted); font-size:.9rem; display:grid; gap:4px; }
        .message-excerpt { margin:14px 0 0; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; color:#1e293b; white-space:pre-wrap; }
        .message-actions-note { margin-top:12px; padding:12px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; color:#1d4ed8; font-size:.92rem; }
        details { border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; background:#fff; }
        details + details { margin-top:10px; }
        summary { cursor:pointer; font-weight:700; }
        .kv-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-top:12px; }
        .kv { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; }
        .kv .k { color:var(--muted); font-size:.8rem; text-transform:uppercase; letter-spacing:.03em; }
        .kv .v { margin-top:4px; font-weight:700; white-space:pre-wrap; word-break:break-word; }
        .empty { color:var(--muted); font-style:italic; padding:14px; border:1px dashed var(--border); border-radius:12px; background:#fff; }
        pre { white-space:pre-wrap; word-break:break-word; background:#0f172a; color:#e2e8f0; padding:14px; border-radius:10px; overflow:auto; }
        code { background:#e2e8f0; padding:2px 5px; border-radius:6px; }
        .muted { color:var(--muted); }
        .log-line { padding:10px 12px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; white-space:pre-wrap; word-break:break-word; }
        .log-line.info { border-left:4px solid #2563eb; }
        .log-line.warning { border-left:4px solid #b45309; }
        .log-line.error { border-left:4px solid #b91c1c; }
        .two-col { display:grid; grid-template-columns: 1.3fr .9fr; gap:16px; }
        @media (max-width: 960px) { .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div>
            <h1 style="margin:0;"><?php echo htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Standalone operator dashboard for the PHP mail assistant client.</p>
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

    <div class="panel" style="margin-bottom:16px;">
        <div class="two-col">
            <div>
                <h2 style="margin:0 0 8px;">Runtime overview</h2>
                <div class="muted">Human-readable operator view of the last run, local continuity history, and fetched Tools config.</div>
            </div>
            <div class="kv-grid">
                <div class="kv"><div class="k">Project root</div><div class="v"><?php echo htmlspecialchars((string) $projectRoot, ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="kv"><div class="k">Tools API</div><div class="v"><?php echo htmlspecialchars((string) $toolsBaseUrl, ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="kv"><div class="k">IMAP extension</div><div class="v"><?php echo $imapAvailable ? 'Available' : 'Missing'; ?></div></div>
                <div class="kv"><div class="k">Config fetch</div><div class="v"><?php echo !empty($configError) ? htmlspecialchars((string) $configError, ENT_QUOTES, 'UTF-8') : 'OK'; ?></div></div>
            </div>
        </div>
    </div>

    <div class="summary-grid" id="summary-grid"></div>

    <div class="tabs">
        <button class="tab-btn active" type="button" data-tab="activity">Inbox activity</button>
        <button class="tab-btn" type="button" data-tab="history">Local history</button>
        <button class="tab-btn" type="button" data-tab="config">Tools config</button>
        <button class="tab-btn" type="button" data-tab="logs">Logs & raw</button>
    </div>

    <section class="tab-panel active" data-tab-panel="activity">
        <div class="mailbox-list" id="activity-panel"></div>
    </section>

    <section class="tab-panel" data-tab-panel="history">
        <div class="history-list" id="history-panel"></div>
    </section>

    <section class="tab-panel" data-tab-panel="config">
        <div class="config-list" id="config-summary-panel"></div>
    </section>

    <section class="tab-panel" data-tab-panel="logs">
        <div class="log-list" id="logs-panel"></div>
    </section>
</div>
<script>
(() => {
    const ajaxBase = <?php echo json_encode($ajaxBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const initialData = <?php echo json_encode([
        'config' => $config,
        'configError' => $configError,
        'lastRun' => $lastRun,
        'messageState' => $messageState,
        'messageCopies' => $messageCopies ?? [],
        'ui' => $ui ?? [],
        'logLines' => $logLines,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const statusNode = document.getElementById('ajax-status');
    const summaryGrid = document.getElementById('summary-grid');
    const activityPanel = document.getElementById('activity-panel');
    const historyPanel = document.getElementById('history-panel');
    const configSummaryPanel = document.getElementById('config-summary-panel');
    const logsPanel = document.getElementById('logs-panel');

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

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const isPlainObject = (value) => value && typeof value === 'object' && !Array.isArray(value);
    const getObject = (value) => isPlainObject(value) ? value : {};

    const renderJsonDetails = (title, value, open = false) => {
        const openAttr = open ? ' open' : '';
        return `<details${openAttr}><summary>${escapeHtml(title)}</summary><pre>${escapeHtml(pretty(value))}</pre></details>`;
    };

    const renderKvGrid = (pairs) => {
        if (!Array.isArray(pairs) || !pairs.length) return '<div class="empty">No details available.</div>';
        return `<div class="kv-grid">${pairs.map((pair) => `
            <div class="kv">
                <div class="k">${escapeHtml(pair.label)}</div>
                <div class="v">${escapeHtml(pair.value ?? '')}</div>
            </div>
        `).join('')}</div>`;
    };

    const toneClass = (tone) => {
        if (!tone) return 'tone-muted';
        return `tone-${tone}`;
    };

    const badgeClass = (value) => {
        const normalized = String(value || '').toLowerCase();
        if (['handled', 'success', 'ok'].includes(normalized)) return 'pill-success';
        if (['warning', 'skipped'].includes(normalized)) return 'pill-warning';
        if (['error', 'failed', 'danger'].includes(normalized)) return 'pill-danger';
        return 'pill-muted';
    };

    const renderSummaryGrid = (overview) => {
        if (!summaryGrid) return;
        if (!Array.isArray(overview) || !overview.length) {
            summaryGrid.innerHTML = '<div class="empty">No overview data available.</div>';
            return;
        }
        summaryGrid.innerHTML = overview.map((item) => `
            <div class="summary-card ${toneClass(item.tone)}">
                <div class="label">${escapeHtml(item.label)}</div>
                <div class="value">${escapeHtml(item.value)}</div>
                <div class="note">${escapeHtml(item.note || '')}</div>
            </div>
        `).join('');
    };

    const renderSelectedRule = (rule) => {
        if (!isPlainObject(rule) || !rule.id) return '<div class="empty">No selected rule.</div>';
        return renderKvGrid([
            { label: 'Rule ID', value: rule.id },
            { label: 'Name', value: rule.name || '' },
            { label: 'Sort order', value: rule.sort_order ?? '' },
            { label: 'Match score', value: rule.match_priority_score ?? '' },
        ]);
    };


    const renderMessageCard = (message) => {
        const copy = isPlainObject(message.copy) ? message.copy : null;
        const genericDecision = isPlainObject(message.generic_ai_decision) ? message.generic_ai_decision : {};
        const matchingRules = Array.isArray(message.matching_rules) ? message.matching_rules : [];
        const evaluatedRows = Array.isArray(genericDecision.evaluated_no_match_rules) ? genericDecision.evaluated_no_match_rules : [];
        const headerPairs = [
            { label: 'Message-ID', value: message.message_id || '' },
            { label: 'In-Reply-To', value: message.in_reply_to || '' },
            { label: 'References', value: Array.isArray(message.references) ? message.references.join('\n') : '' },
            { label: 'Thread key', value: message.thread_key || '' },
            { label: 'Reply Message-ID', value: message.reply_message_id || '' },
        ].filter((row) => String(row.value || '').trim() !== '');

        return `
            <article class="message-card">
                <div class="message-top">
                    <div>
                        <h3 class="message-subject">${escapeHtml(message.subject || '(no subject)')}</h3>
                        <div class="message-meta">
                            <div><strong>From:</strong> ${escapeHtml(message.from || '')}</div>
                            <div><strong>To:</strong> ${escapeHtml(message.to || '')}</div>
                            <div><strong>Date:</strong> ${escapeHtml(message.date || '')}</div>
                            <div><strong>Reason:</strong> ${escapeHtml(message.reason_label || message.reason || '')}</div>
                        </div>
                    </div>
                    <div class="pill-row">
                        <span class="pill ${badgeClass(message.outcome)}">${escapeHtml(message.outcome || 'unknown')}</span>
                        ${message.rule_resolution_source ? `<span class="pill pill-muted">${escapeHtml(message.rule_resolution_source)}</span>` : ''}
                        ${message.reply_transport ? `<span class="pill pill-muted">reply via ${escapeHtml(message.reply_transport)}</span>` : ''}
                    </div>
                </div>
                ${message.body_excerpt ? `<div class="message-excerpt">${escapeHtml(message.body_excerpt)}</div>` : ''}
                <div class="message-actions-note">Phase 1 dashboard: readable operator inbox. Manual reply / apply-rule actions are intentionally not wired yet, but this card now exposes the thread and rule data needed for that next step.</div>
                <details>
                    <summary>Thread & header diagnostics</summary>
                    ${renderKvGrid(headerPairs)}
                    ${copy && isPlainObject(copy.headers_map) && Object.keys(copy.headers_map).length
                        ? renderJsonDetails('Saved local header map', copy.headers_map)
                        : '<div class="empty" style="margin-top:10px;">No saved full headers for this message yet.</div>'}
                </details>
                <details>
                    <summary>Rule decision</summary>
                    ${renderSelectedRule(message.selected_rule)}
                    ${matchingRules.length ? renderJsonDetails(`Matching rules (${matchingRules.length})`, matchingRules) : '<div class="empty" style="margin-top:10px;">No matching rules were recorded for this message.</div>'}
                    ${Object.keys(genericDecision).length ? renderJsonDetails('Generic no-match decision', genericDecision, false) : ''}
                    ${evaluatedRows.length ? renderJsonDetails(`Evaluated unmatched rows (${evaluatedRows.length})`, evaluatedRows) : ''}
                </details>
                ${(message.reused_from_message_id || copy)
                    ? `<details><summary>Continuity & local copy</summary>
                        ${renderKvGrid([
                            { label: 'Reused from message', value: message.reused_from_message_id || '' },
                            { label: 'Saved local copy', value: copy ? (copy.filename || '') : '' },
                            { label: 'Copy reason', value: copy ? (copy.reason || '') : '' },
                            { label: 'Copy saved at', value: copy ? (copy.saved_at || '') : '' },
                        ].filter((row) => String(row.value || '').trim() !== ''))}
                    </details>`
                    : ''}
            </article>
        `;
    };

    const renderActivity = (mailboxes) => {
        if (!activityPanel) return;
        if (!Array.isArray(mailboxes) || !mailboxes.length) {
            activityPanel.innerHTML = '<div class="empty">No saved run activity yet. Use dry-run or a real run first.</div>';
            return;
        }

        activityPanel.innerHTML = mailboxes.map((mailbox) => `
            <section class="mailbox-card">
                <div class="mailbox-head">
                    <div>
                        <h2 style="margin:0;">${escapeHtml(mailbox.name || 'Mailbox')}</h2>
                        <div class="muted">Mailbox #${escapeHtml(mailbox.id || '')}</div>
                    </div>
                    <div class="stats-row">
                        <span class="stat-pill">scanned ${escapeHtml(mailbox.scanned)}</span>
                        <span class="stat-pill">handled ${escapeHtml(mailbox.handled)}</span>
                        <span class="stat-pill">skipped ${escapeHtml(mailbox.skipped)}</span>
                        <span class="stat-pill">failed ${escapeHtml(mailbox.failed)}</span>
                    </div>
                </div>
                ${Array.isArray(mailbox.messages) && mailbox.messages.length
                    ? `<div class="message-list">${mailbox.messages.map(renderMessageCard).join('')}</div>`
                    : '<div class="empty">No per-message activity recorded.</div>'}
                ${Array.isArray(mailbox.errors) && mailbox.errors.length ? renderJsonDetails('Mailbox errors', mailbox.errors) : ''}
            </section>
        `).join('');
    };

    const renderHistory = (mailboxes) => {
        if (!historyPanel) return;
        if (!Array.isArray(mailboxes) || !mailboxes.length) {
            historyPanel.innerHTML = '<div class="empty">No local message history recorded yet.</div>';
            return;
        }

        historyPanel.innerHTML = mailboxes.map((mailbox) => `
            <section class="history-card">
                <div class="history-head">
                    <div>
                        <h2 style="margin:0;">Mailbox history #${escapeHtml(mailbox.id || '')}</h2>
                        <div class="muted">Diagnostic continuity store under <code>storage/state</code>.</div>
                    </div>
                    <div class="stats-row">
                        <span class="stat-pill">records ${escapeHtml(mailbox.count || 0)}</span>
                        <span class="stat-pill">pending ${escapeHtml(mailbox.count_pending || 0)}</span>
                        <span class="stat-pill">already replied ${escapeHtml(mailbox.count_already_replied || 0)}</span>
                    </div>
                </div>
                ${renderJsonDetails('Recent pending entries', mailbox.recent || [])}
                ${renderJsonDetails('Recent all entries', mailbox.recent_all || [])}
                ${renderJsonDetails('Status counts', mailbox.status_counts || {})}
            </section>
        `).join('');
    };

    const renderConfig = (configSummary) => {
        if (!configSummaryPanel) return;
        const configData = getObject(configSummary);
        const mailboxes = Array.isArray(configData.mailboxes) ? configData.mailboxes : [];
        if (!mailboxes.length) {
            configSummaryPanel.innerHTML = '<div class="empty">No Tools config summary available.</div>';
            return;
        }

        const userBlock = isPlainObject(configData.user) && Object.keys(configData.user).length
            ? renderJsonDetails('Resolved user', configData.user)
            : '';
        const tokenBlock = isPlainObject(configData.token) && Object.keys(configData.token).length
            ? renderJsonDetails('Resolved token', configData.token)
            : '';

        configSummaryPanel.innerHTML = `${userBlock}${tokenBlock}${mailboxes.map((mailbox) => `
            <section class="config-card">
                <div class="config-head">
                    <div>
                        <h2 style="margin:0;">${escapeHtml(mailbox.name || 'Mailbox')}</h2>
                        <div class="muted">IMAP ${escapeHtml((mailbox.imap || {}).host || '')}:${escapeHtml((mailbox.imap || {}).port || '')} / ${escapeHtml((mailbox.imap || {}).folder || 'INBOX')}</div>
                    </div>
                    <div class="stats-row">
                        <span class="stat-pill">rules ${escapeHtml(mailbox.rule_count || 0)}</span>
                        <span class="stat-pill">no-match rows ${escapeHtml(mailbox.no_match_rule_count || 0)}</span>
                    </div>
                </div>
                ${renderKvGrid([
                    { label: 'From name', value: (mailbox.defaults || {}).from_name || '' },
                    { label: 'From email', value: (mailbox.defaults || {}).from_email || '' },
                    { label: 'Run limit', value: (mailbox.defaults || {}).run_limit || '' },
                    { label: 'No-match AI', value: (mailbox.defaults || {}).generic_no_match_ai_enabled ? 'Enabled' : 'Disabled' },
                    { label: 'Reply spam threshold', value: (mailbox.defaults || {}).spam_score_reply_threshold ?? '' },
                ])}
                ${renderJsonDetails('Matched rules', mailbox.rules || [])}
                ${renderJsonDetails('Unmatched fallback rows', mailbox.no_match_rules || [])}
            </section>
        `).join('')}`;
    };

    const renderLogs = (payload) => {
        if (!logsPanel) return;
        const data = getObject(payload);
        const ui = getObject(data.ui);
        const logEntries = Array.isArray(ui.logs) ? ui.logs : [];
        const sections = [];

        sections.push(`
            <section class="log-card">
                <h2 style="margin-top:0;">Recent local logs</h2>
                ${logEntries.length
                    ? logEntries.map((entry) => `<div class="log-line ${escapeHtml(entry.level || 'info')}">${escapeHtml(entry.line || '')}</div>`).join('')
                    : '<div class="empty">No logs yet.</div>'}
            </section>
        `);

        sections.push(`
            <section class="log-card">
                <h2 style="margin-top:0;">Raw diagnostic data</h2>
                ${renderJsonDetails('Last run JSON', payload?.lastRun || {}, false)}
                ${renderJsonDetails('Message-state JSON', payload?.messageState || {}, false)}
                ${renderJsonDetails('Fetched config JSON', payload?.config || {}, false)}
            </section>
        `);

        logsPanel.innerHTML = sections.join('');
    };

    const applyDashboard = (payload) => {
        const data = isPlainObject(payload) && isPlainObject(payload.data) ? payload.data : (isPlainObject(payload) ? payload : {});
        const ui = getObject(data.ui);
        renderSummaryGrid(ui.overview || []);
        renderActivity(ui.activity || []);
        renderHistory(ui.history || []);
        renderConfig(ui.config || {});
        renderLogs(data);
    };

    document.querySelectorAll('[data-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab');
            document.querySelectorAll('[data-tab]').forEach((candidate) => candidate.classList.toggle('active', candidate === button));
            document.querySelectorAll('[data-tab-panel]').forEach((panel) => {
                panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target);
            });
        });
    });

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

    applyDashboard(initialData);
})();
</script>
</body>
</html>




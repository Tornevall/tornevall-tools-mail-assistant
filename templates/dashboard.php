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
        .mailbox-list, .history-list, .config-list, .log-list, .rule-list { display:grid; gap:16px; }
        .mailbox-card, .history-card, .config-card, .log-card { padding:18px; }
        .mailbox-head, .history-head, .config-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
        .section-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center; margin:16px 0 10px; }
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
        .alert-list { display:grid; gap:12px; margin:0 0 18px; }
        .alert-card { padding:14px 16px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:var(--shadow); }
        .alert-card h3 { margin:0 0 6px; font-size:1rem; }
        .alert-card p { margin:0; white-space:pre-wrap; }
        .alert-card.alert-danger { border-color:#fecaca; background:#fef2f2; color:#7f1d1d; }
        .alert-card.alert-warning { border-color:#fde68a; background:#fffbeb; color:#92400e; }
        .alert-card.alert-info { border-color:#bfdbfe; background:#eff6ff; color:#1d4ed8; }
        .info-note { margin-top:12px; padding:12px 14px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:12px; color:#334155; font-size:.92rem; }
        .operator-form { margin-top:14px; display:grid; gap:10px; padding:14px; border:1px solid #dbeafe; border-radius:12px; background:#f8fbff; }
        .operator-form h4 { margin:0; font-size:1rem; }
        .operator-grid { display:grid; grid-template-columns: minmax(220px, 280px) 1fr; gap:10px; }
        .operator-grid label, .operator-note label { display:grid; gap:6px; font-size:.88rem; color:var(--muted); font-weight:700; }
        .operator-grid select, .operator-grid textarea, .operator-note textarea { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; font:inherit; background:#fff; color:var(--text); }
        .operator-grid textarea, .operator-note textarea { min-height:110px; resize:vertical; }
        .operator-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .operator-actions .btn { padding:9px 12px; }
        .operator-inline-status { display:none; padding:10px 12px; border-radius:10px; border:1px solid #cbd5e1; font-size:.9rem; }
        .operator-inline-status.active { display:block; }
        .operator-inline-status.success { border-color:#86efac; background:#f0fdf4; color:#166534; }
        .operator-inline-status.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
        .operator-inline-status.pending { border-color:#bfdbfe; background:#eff6ff; color:#1d4ed8; }
        .rule-card { padding:14px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; }
        .rule-card h3 { margin:0; font-size:1rem; }
        .rule-card p { margin:.45rem 0 0; }
        .subtle-list { display:grid; gap:10px; margin-top:12px; }
        .text-block { margin-top:10px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; white-space:pre-wrap; }
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

    <div class="alert-list" id="alerts-panel"></div>

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
        <button class="tab-btn active" type="button" data-tab="activity">Latest run / inbox activity</button>
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
    const alertsPanel = document.getElementById('alerts-panel');
    const summaryGrid = document.getElementById('summary-grid');
    const activityPanel = document.getElementById('activity-panel');
    const historyPanel = document.getElementById('history-panel');
    const configSummaryPanel = document.getElementById('config-summary-panel');
    const logsPanel = document.getElementById('logs-panel');

    let currentData = initialData;

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

    const renderTextBlock = (label, value) => {
        const normalized = String(value || '').trim();
        if (!normalized) return '';
        return `
            <div>
                <div class="k">${escapeHtml(label)}</div>
                <div class="text-block">${escapeHtml(normalized)}</div>
            </div>
        `;
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

    const renderAlerts = (alerts) => {
        if (!alertsPanel) return;
        if (!Array.isArray(alerts) || !alerts.length) {
            alertsPanel.innerHTML = '';
            return;
        }
        alertsPanel.innerHTML = alerts.map((alert) => `
            <article class="alert-card alert-${escapeHtml(alert.severity || 'info')}">
                <h3>${escapeHtml(alert.title || 'Runtime alert')}</h3>
                <p>${escapeHtml(alert.message || '')}</p>
            </article>
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


    const renderMessageCard = (message, mailbox) => {
        const copy = isPlainObject(message.copy) ? message.copy : null;
        const supportCase = isPlainObject(message.support_case) ? message.support_case : null;
        const genericDecision = isPlainObject(message.generic_ai_decision) ? message.generic_ai_decision : {};
        const matchingRules = Array.isArray(message.matching_rules) ? message.matching_rules : [];
        const evaluatedRows = Array.isArray(genericDecision.evaluated_no_match_rules) ? genericDecision.evaluated_no_match_rules : [];
        const availableRules = Array.isArray(mailbox.available_rules) ? mailbox.available_rules : [];
        const visibleBody = String(message.body_preview || (copy && copy.body_preview) || message.body_excerpt || '').trim();
        const ruleOptions = ['<option value="">No local rule assignment</option>'].concat(availableRules.map((rule) => {
            const selected = Number(rule.id) === Number(message.selected_rule?.id || 0) ? ' selected' : '';
            return `<option value="${escapeHtml(rule.id)}"${selected}>#${escapeHtml(rule.id)} · ${escapeHtml(rule.name || 'Rule')} (sort ${escapeHtml(rule.sort_order || 0)})</option>`;
        })).join('');
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
                ${visibleBody ? `<div class="message-excerpt">${escapeHtml(visibleBody)}</div>` : ''}
                <div class="message-actions-note">
                    This lightweight operator inbox now supports local rule assignment, styled manual replies, and manual mark-handled actions.
                    ${mailbox.source === 'live_inbox'
                        ? ' This card currently comes from the live unread IMAP preview, so you do not have to wait for another saved run before acting on it.'
                        : ' This card currently comes from the latest saved run summary.'}
                </div>
                ${supportCase
                    ? `<div class="operator-actions" style="margin-top:12px;">
                        <a class="btn mutedbtn" href="${escapeHtml(supportCase.admin_url || '#')}" target="_blank" rel="noopener">Open case in Tools</a>
                        ${(supportCase.public_url || '') ? `<a class="btn secondary" href="${escapeHtml(supportCase.public_url || '')}" target="_blank" rel="noopener">Open public case link</a>` : ''}
                    </div>`
                    : ''}
                <form class="operator-form" data-manual-form>
                    <h4>Manual handling</h4>
                    <input type="hidden" name="mailbox_id" value="${escapeHtml(mailbox.id || 0)}">
                    <input type="hidden" name="uid" value="${escapeHtml(message.uid || 0)}">
                    <input type="hidden" name="message_id" value="${escapeHtml(message.message_id || '')}">
                    <input type="hidden" name="message_key" value="${escapeHtml(message.message_key || '')}">
                    <div class="operator-grid">
                        <label>
                            Assign rule / context
                            <select name="rule_id">${ruleOptions}</select>
                        </label>
                        <label>
                            Manual reply body
                            <textarea name="body" placeholder="Write a manual reply here. The assistant will send it with the same styled text/HTML wrapper and original-request summary flow used for automatic replies."></textarea>
                        </label>
                    </div>
                    <label class="operator-note">
                        Optional internal operator note
                        <textarea name="note" placeholder="Optional note for manual handled/read action."></textarea>
                    </label>
                    <div class="operator-actions">
                        <button class="btn secondary" type="button" data-manual-action="manual-reply">Send manual reply</button>
                        <button class="btn mutedbtn" type="button" data-manual-action="manual-mark-handled">Mark handled / read</button>
                    </div>
                    <div class="operator-inline-status" data-manual-status></div>
                </form>
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
                        ${copy && copy.body_preview ? renderTextBlock('Saved local body preview', copy.body_preview) : ''}
                    </details>`
                    : ''}
            </article>
        `;
    };

    const renderActivity = (mailboxes) => {
        if (!activityPanel) return;
        if (!Array.isArray(mailboxes) || !mailboxes.length) {
            activityPanel.innerHTML = '<div class="empty">No saved run activity yet. This panel is a latest-run operator inbox, not a live IMAP browser. Use dry-run or a real run first.</div>';
            return;
        }

        activityPanel.innerHTML = mailboxes.map((mailbox) => `
            <section class="mailbox-card">
                <div class="mailbox-head">
                    <div>
                        <h2 style="margin:0;">${escapeHtml(mailbox.name || 'Mailbox')}</h2>
                        <div class="muted">Mailbox #${escapeHtml(mailbox.id || '')}</div>
                        ${(mailbox.imap && mailbox.imap.host)
                            ? `<div class="muted">IMAP ${escapeHtml(mailbox.imap.host || '')}:${escapeHtml(mailbox.imap.port || '')} / ${escapeHtml(mailbox.imap.folder || 'INBOX')}</div>`
                            : ''}
                    </div>
                    <div class="stats-row">
                        <span class="stat-pill">scanned ${escapeHtml(mailbox.scanned)}</span>
                        <span class="stat-pill">handled ${escapeHtml(mailbox.handled)}</span>
                        <span class="stat-pill">skipped ${escapeHtml(mailbox.skipped)}</span>
                        <span class="stat-pill">failed ${escapeHtml(mailbox.failed)}</span>
                    </div>
                </div>
                ${mailbox.source === 'config_only'
                    ? '<div class="info-note">No saved run has touched this mailbox yet. The dashboard can show that the mailbox exists in Tools config, but it only becomes a readable inbox-style activity view after a dry-run or real polling pass records message results.</div>'
                    : mailbox.source === 'live_inbox'
                        ? '<div class="info-note">This mailbox currently shows the live unread IMAP preview, so you can inspect and act on fresh unread mail even before another saved run exists.</div>'
                        : '<div class="info-note">This is the latest saved run for this mailbox. The standalone dashboard is intentionally a lightweight operator inbox, not a full IMAP admin client.</div>'}
                ${Array.isArray(mailbox.messages) && mailbox.messages.length
                    ? `<div class="message-list">${mailbox.messages.map((message) => renderMessageCard(message, mailbox)).join('')}</div>`
                    : '<div class="empty">No per-message activity recorded for this mailbox yet.</div>'}
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

    const renderRuleCard = (rule) => {
        const reply = getObject(rule.reply);
        const postHandle = getObject(rule.post_handle);
        const fallbackRule = getObject(rule.fallback_rule);
        const match = getObject(rule.match);
        return `
            <article class="rule-card">
                <div class="section-head" style="margin-top:0;">
                    <div>
                        <h3>${escapeHtml(rule.name || 'Rule')}</h3>
                        <div class="muted">Rule #${escapeHtml(rule.id || '')} · sort ${escapeHtml(rule.sort_order || 0)}</div>
                    </div>
                    <div class="pill-row">
                        <span class="pill ${rule.reply_enabled ? 'pill-success' : 'pill-muted'}">reply ${rule.reply_enabled ? 'enabled' : 'disabled'}</span>
                        <span class="pill ${rule.ai_enabled ? 'pill-success' : 'pill-muted'}">AI ${rule.ai_enabled ? 'enabled' : 'disabled'}</span>
                    </div>
                </div>
                ${renderKvGrid([
                    { label: 'From contains', value: match.from_contains || '' },
                    { label: 'To contains', value: match.to_contains || '' },
                    { label: 'Subject contains', value: match.subject_contains || '' },
                    { label: 'Body contains', value: match.body_contains || '' },
                    { label: 'Reply subject prefix', value: reply.subject_prefix || '' },
                    { label: 'Reply from', value: [reply.from_name || '', reply.from_email || ''].filter(Boolean).join(' <') + ([reply.from_name, reply.from_email].every(Boolean) ? '>' : '') },
                    { label: 'BCC', value: reply.bcc || '' },
                    { label: 'Footer mode', value: reply.footer_mode || '' },
                    { label: 'Footer text', value: reply.footer_text || '' },
                    { label: 'Responder', value: reply.responder_name || '' },
                    { label: 'Persona', value: reply.persona_profile || '' },
                    { label: 'Mood', value: reply.mood || '' },
                    { label: 'AI model', value: reply.ai_model || '' },
                    { label: 'Reasoning', value: reply.ai_reasoning_effort || '' },
                    { label: 'Move to folder', value: postHandle.move_to_folder || '' },
                    { label: 'Delete after handle', value: postHandle.delete_after_handle ? 'Yes' : 'No' },
                    { label: 'Fallback enabled', value: fallbackRule.enabled ? 'Yes' : 'No' },
                    { label: 'Fallback AI model', value: fallbackRule.ai_model || '' },
                    { label: 'Fallback reasoning', value: fallbackRule.ai_reasoning_effort || '' },
                    { label: 'Subject trim prefixes', value: Array.isArray(rule.subject_trim_prefixes) ? rule.subject_trim_prefixes.join(', ') : '' },
                ].filter((row) => String(row.value || '').trim() !== ''))}
                ${renderTextBlock('Static template text', reply.template_text || '')}
                ${renderTextBlock('Custom instruction', reply.custom_instruction || '')}
                ${renderTextBlock('Fallback IF condition', fallbackRule.if_condition || '')}
                ${renderTextBlock('Fallback instruction', fallbackRule.instruction || '')}
            </article>
        `;
    };

    const renderNoMatchRuleCard = (row) => `
        <article class="rule-card">
            <div class="section-head" style="margin-top:0;">
                <div>
                    <h3>Unmatched row #${escapeHtml(row.id || '')}</h3>
                    <div class="muted">sort ${escapeHtml(row.sort_order || 0)}</div>
                </div>
                <div class="pill-row">
                    <span class="pill ${row.is_active ? 'pill-success' : 'pill-muted'}">${row.is_active ? 'active' : 'inactive'}</span>
                    ${row.ai_model ? `<span class="pill pill-muted">AI ${escapeHtml(row.ai_model)}</span>` : ''}
                    ${row.ai_reasoning_effort ? `<span class="pill pill-muted">${escapeHtml(row.ai_reasoning_effort)}</span>` : ''}
                </div>
            </div>
            ${renderTextBlock('IF condition', row.if || '')}
            ${renderTextBlock('Instruction', row.instruction || '')}
            ${renderKvGrid([
                { label: 'Footer', value: row.footer || '' },
                { label: 'AI model', value: row.ai_model || '' },
                { label: 'Reasoning', value: row.ai_reasoning_effort || '' },
            ].filter((entry) => String(entry.value || '').trim() !== ''))}
        </article>
    `;

    const renderConfig = (configSummary) => {
        if (!configSummaryPanel) return;
        const configData = getObject(configSummary);
        const mailboxes = Array.isArray(configData.mailboxes) ? configData.mailboxes : [];
        const emptyReason = String(configData.empty_reason || configData.error || '').trim();
        if (!mailboxes.length) {
            configSummaryPanel.innerHTML = `<div class="empty">${escapeHtml(emptyReason || 'No Tools config summary available.')}</div>`;
            return;
        }

        const userBlock = isPlainObject(configData.user) && Object.keys(configData.user).length
            ? renderJsonDetails('Resolved user', configData.user)
            : '';
        const tokenBlock = isPlainObject(configData.token) && Object.keys(configData.token).length
            ? renderJsonDetails('Resolved token', configData.token)
            : '';
        const intro = `<div class="info-note">This tab mirrors the currently visible Tools config for the active standalone token. Mailboxes/rules are still edited in Tools admin; the standalone dashboard shows what the runner can currently see and use.</div>`;

        configSummaryPanel.innerHTML = `${intro}${userBlock}${tokenBlock}${mailboxes.map((mailbox) => `
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
                    { label: 'Default BCC', value: (mailbox.defaults || {}).bcc || '' },
                    { label: 'Run limit', value: (mailbox.defaults || {}).run_limit || '' },
                    { label: 'Mark seen on skip', value: (mailbox.defaults || {}).mark_seen_on_skip ? 'Yes' : 'No' },
                    { label: 'No-match AI', value: (mailbox.defaults || {}).generic_no_match_ai_enabled ? 'Enabled' : 'Disabled' },
                    { label: 'No-match AI model', value: (mailbox.defaults || {}).generic_no_match_ai_model || '' },
                    { label: 'No-match reasoning', value: (mailbox.defaults || {}).generic_no_match_ai_reasoning_effort || '' },
                    { label: 'Reply spam threshold', value: (mailbox.defaults || {}).spam_score_reply_threshold ?? '' },
                    { label: 'Generic IF alias', value: (mailbox.defaults || {}).generic_no_match_if || '' },
                    { label: 'Generic footer alias', value: (mailbox.defaults || {}).generic_no_match_footer || '' },
                    { label: 'Subject trim prefixes', value: Array.isArray((mailbox.defaults || {}).subject_trim_prefixes) ? (mailbox.defaults || {}).subject_trim_prefixes.join(', ') : '' },
                ].filter((entry) => String(entry.value || '').trim() !== ''))}
                ${renderTextBlock('Default footer', (mailbox.defaults || {}).footer || '')}
                ${renderTextBlock('Generic unmatched instruction alias', (mailbox.defaults || {}).generic_no_match_instruction || '')}
                ${renderTextBlock('Mailbox notes', mailbox.notes || '')}
                <div class="section-head">
                    <h3 style="margin:0;">Matched rule rows</h3>
                    <div class="muted">These are the Tools rule rows the standalone runner can currently use.</div>
                </div>
                ${Array.isArray(mailbox.rules) && mailbox.rules.length
                    ? `<div class="rule-list">${mailbox.rules.map(renderRuleCard).join('')}</div>`
                    : '<div class="empty">No active matched rules are visible for this mailbox.</div>'}
                <div class="section-head">
                    <h3 style="margin:0;">Unmatched AI / IF rows</h3>
                    <div class="muted">Ordered generic_no_match_rules[] rows from Tools.</div>
                </div>
                ${Array.isArray(mailbox.no_match_rules) && mailbox.no_match_rules.length
                    ? `<div class="rule-list">${mailbox.no_match_rules.map(renderNoMatchRuleCard).join('')}</div>`
                    : '<div class="empty">No active unmatched fallback IF rows are visible for this mailbox.</div>'}
                ${renderJsonDetails('Matched rules (raw JSON)', mailbox.rules || [])}
                ${renderJsonDetails('Unmatched fallback rows (raw JSON)', mailbox.no_match_rules || [])}
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
        currentData = data;
        const ui = getObject(data.ui);
        renderAlerts(ui.alerts || []);
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

    const setManualStatus = (form, message, state) => {
        const node = form?.querySelector('[data-manual-status]');
        if (!node) return;
        node.className = `operator-inline-status active ${state}`;
        node.textContent = message;
    };

    if (activityPanel) {
        activityPanel.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-manual-action]');
            if (!button) return;

            const form = button.closest('[data-manual-form]');
            if (!form) return;

            const action = button.getAttribute('data-manual-action');
            const body = String(form.querySelector('textarea[name="body"]')?.value || '').trim();
            if (action === 'manual-reply' && body === '') {
                setManualStatus(form, 'Write a manual reply before sending.', 'error');
                return;
            }

            const formData = new URLSearchParams(new FormData(form));
            const buttons = Array.from(form.querySelectorAll('button'));
            buttons.forEach((node) => { node.disabled = true; });
            setManualStatus(form, action === 'manual-reply' ? 'Sending manual reply…' : 'Marking message handled…', 'pending');
            setStatus(action === 'manual-reply' ? 'Sending manual reply…' : 'Marking message handled…');

            try {
                const payload = await request(action, {
                    method: 'POST',
                    body: formData.toString(),
                });
                applyDashboard(payload.data ?? payload);
                const successMessage = payload.message || (action === 'manual-reply' ? 'Manual reply sent.' : 'Message marked handled.');
                setStatus(successMessage);
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Manual mail action failed.';
                setStatus(message, true);
                setManualStatus(form, message, 'error');
                buttons.forEach((node) => { node.disabled = false; });
                return;
            }
        });
    }

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




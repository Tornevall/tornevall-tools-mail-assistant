<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mail Support Assistant</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background:#0f172a; color:#e2e8f0; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
        .card { width:min(420px, 92vw); background:#111827; border:1px solid #334155; border-radius:14px; padding:24px; box-shadow:0 20px 45px rgba(0,0,0,.35); }
        input { width:100%; padding:10px 12px; margin-top:6px; margin-bottom:14px; border-radius:8px; border:1px solid #475569; background:#0f172a; color:#e2e8f0; }
        button { width:100%; padding:12px; border:none; border-radius:10px; background:#2563eb; color:#fff; font-weight:700; cursor:pointer; }
        .error { background:#7f1d1d; color:#fecaca; padding:10px 12px; border-radius:8px; margin-bottom:14px; }
        small { color:#94a3b8; }
    </style>
</head>
<body>
<div class="card">
    <h1 style="margin-top:0;">Mail Support Assistant</h1>
    <p style="color:#94a3b8;">Local web login for the standalone client dashboard.</p>

    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="username">Username</label>
        <input id="username" type="text" name="username" required autocomplete="username">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <button type="submit">Log in</button>
    </form>

    <p style="margin-bottom:0;"><small>Credentials come from <code>.env</code> (`MAIL_ASSISTANT_WEB_USER` / `MAIL_ASSISTANT_WEB_PASSWORD`).</small></p>
</div>
</body>
</html>


# Tornevall Tools Mail Assistant

Standalone PHP client for the Tools **Mail Support Assistant** workflow.

This project is intentionally small and can stay **databaseless**:

- mailbox + rule configuration lives in Tools admin
- the client fetches config from `GET /api/mail-support-assistant/config`
- local state is limited to session data, logs, the last run summary, and persisted handled/ignored message IDs in `storage/`

The project is **not** a Laravel app and must stay runnable as plain PHP.

## What is included

- `run` - CLI entrypoint for cron/manual runs
- `cron-run.sh` - tiny shell wrapper for cron jobs
- `public/index.php` - mini web UI with env-based login
- `src/` - Tools API client, IMAP adapter, MIME decoding, runner, and local auth
- `templates/` - minimal login/dashboard templates
- `storage/` - logs, last-run summary, and persisted local state
- `storage/state/message-state.json` - normalized handled/ignored `Message-Id` history per mailbox

## Requirements

- PHP 7.4+
- `ext-curl`
- `ext-json`
- `ext-session`
- `ext-imap` recommended for real mailbox handling

Without `ext-imap`, the project still boots and the UI works, but real mailbox polling will fail with a clear runtime message.

## Setup

1. Copy `.env.example` to `.env`
2. Set:
   - `MAIL_ASSISTANT_WEB_USER`
   - `MAIL_ASSISTANT_WEB_PASSWORD`
   - `MAIL_ASSISTANT_TOOLS_TOKEN`
   - optional: `MAIL_ASSISTANT_SPAMASSASSIN_SKIP_SCORE` and `MAIL_ASSISTANT_SPAMASSASSIN_COPY_SCORE`
   - optional AI tuning: `MAIL_ASSISTANT_AI_MODEL`, `MAIL_ASSISTANT_AI_FALLBACK_MODEL`, `MAIL_ASSISTANT_AI_REASONING_EFFORT`
3. In Tools admin, open `/admin/mail-support-assistant`
4. Create mailbox/rule config
5. Generate or rotate a personal `provider_mail_support_assistant` token there
6. Paste that token into this project's `.env`

### Where mailbox credentials are stored

- IMAP host/user/password data is stored in the main Tools admin database, not in this standalone project's own database.
- This standalone client stays databaseless locally; it only keeps `.env`, session state, logs, saved dry-run/last-run summaries, local handled/ignored `Message-Id` state, and optional local message copies in `storage/`.

## CLI usage

```bash
php run --help
php run --self-test
php run --dry-run
php run --dry-run --limit=5
php run --mailbox=12 --dry-run
```

### What `--dry-run` does

- fetches config from Tools
- resolves matching rules
- builds reply payloads
- skips actual reply send / IMAP move / IMAP delete actions

## Mini web UI

Point your web server to `public/` and log in with the env credentials.

Current UI features:

- env-driven login/logout
- AJAX refresh of config/log/last-run panels without reloading the page
- AJAX self-test action
- AJAX-triggered safe dry-run action (reuses the same PHP runner as CLI)
- config preview fetched live from Tools
- last-run summary preview from `storage/last-run.json`
- local handled/ignored `Message-Id` summary preview from `storage/state/message-state.json`
- direct link back to Tools admin
- recent local log tail

### Web UI vs cron execution

- **Cron/manual execution should still use PHP CLI**: `php run ...`
- The web UI calls the same runner class for manual checks and dry-runs, but it is intended as an operator surface, not as the primary cron transport.

## Cron example

```bash
cd /path/to/mail-support-assistant
php run --limit=10 >> storage/logs/cron.log 2>&1
```

## AI behavior

Rules can decide per message whether AI is enabled.

- If a rule has `ai_enabled=false`, the client uses the static template text only.
- If a rule has `ai_enabled=true`, the client calls Tools' `POST /api/ai/socialgpt/respond` with the same personal token.
- AI requests now default to a primary model (`gpt-5.4`) and retry once with a fallback model (`gpt-4o-mini`) if the primary call fails.
- The same reasoning-effort setting is forwarded on both primary and fallback requests (`MAIL_ASSISTANT_AI_REASONING_EFFORT`, default `medium`).
- Message bodies are sanitized (HTML/MIME noise stripped) before being sent as AI request summary context.
- The token owner still needs approved `provider_openai` access in Tools unless that user is admin.

## Notes

- Unmatched mail is left untouched.
- Cron/manual execution still only polls unread mail, but any message that gets handled or explicitly ignored is also persisted locally by normalized `Message-Id` (with a fallback key when the header is missing) so leftover unread mail is not processed twice.
- Matchers currently support `from`, `to`, `subject`, and optional body text contains checks.
- The runner now parses SpamAssassin headers so heavily flagged messages can be skipped before handling, while wrapper-style SpamAssassin rewrites can still be copied locally and stripped from the body before rule matching/AI.
- Local SpamAssassin/debug copies are written under `storage/cache/message-copies/` when the runner detects a rewritten wrapper or another message worth preserving for review.
- The mini dashboard now shows both the last run summary and the local `Message-Id` state so operators can see which mailbox records were already handled or ignored.
- Reply sending uses PHP `mail()` for the first scaffold. If you later want SMTP or a dedicated mail transport, extend `MailAssistantRunner::sendReply()`.


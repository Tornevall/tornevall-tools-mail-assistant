# Tornevall Tools Mail Assistant

Standalone PHP client for the Tools **Mail Support Assistant** workflow.

This project is intentionally small and can stay **databaseless**:

- mailbox + rule configuration lives in Tools admin
- the client fetches config from `GET /api/mail-support-assistant/config`
- local state is limited to session data, logs, the last run summary, and a local message-history file in `storage/`

The project is **not** a Laravel app and must stay runnable as plain PHP.

## What is included

- `run` - CLI entrypoint for cron/manual runs
- `cron-run.sh` - tiny shell wrapper for cron jobs
- `public/index.php` - mini web UI with env-based login
- `src/` - Tools API client, IMAP adapter, MIME decoding, runner, and local auth
- `templates/` - minimal login/dashboard templates
- `storage/` - logs, last-run summary, and persisted local state
- `storage/state/message-state.json` - normalized local message history per mailbox for diagnostics

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
   - optional dedicated relay token: `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`
   - optional: `MAIL_ASSISTANT_SPAMASSASSIN_SKIP_SCORE` and `MAIL_ASSISTANT_SPAMASSASSIN_COPY_SCORE`
   - optional AI tuning: `MAIL_ASSISTANT_AI_MODEL`, `MAIL_ASSISTANT_AI_FALLBACK_MODEL`, `MAIL_ASSISTANT_AI_REASONING_EFFORT`
   - optional mail transport tuning:
     - `MAIL_ASSISTANT_MAIL_TRANSPORT` (`smtp` | `pickup` | `php_mail` | `custom_mta` | `tools_api`)
     - `MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS` (optional comma-separated fallback order such as `smtp,tools_api,pickup`)
     - SMTP keys: `MAIL_ASSISTANT_SMTP_HOST`, `MAIL_ASSISTANT_SMTP_PORT`, `MAIL_ASSISTANT_SMTP_SECURITY`, `MAIL_ASSISTANT_SMTP_USERNAME`, `MAIL_ASSISTANT_SMTP_PASSWORD`, `MAIL_ASSISTANT_SMTP_EHLO`, `MAIL_ASSISTANT_SMTP_TIMEOUT`, `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE`
     - `MAIL_ASSISTANT_MTA_COMMAND` (used when transport is `custom_mta`)
     - `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API` (`true|false`)
   - optional no-match fallback gate: `MAIL_ASSISTANT_GENERIC_NO_MATCH_AI=1` (kept off by default unless enabled in Tools config or env)
3. In Tools admin, open `/admin/mail-support-assistant`
4. Create mailbox/rule config
5. Generate or rotate a personal `provider_mail_support_assistant` token there
6. Paste that token into this project's `.env`

### Where mailbox credentials are stored

- IMAP host/user/password data is stored in the main Tools admin database, not in this standalone project's own database.
- This standalone client stays databaseless locally; it only keeps `.env`, session state, logs, saved dry-run/last-run summaries, local message-history state, and optional local message copies in `storage/`.
- Because mailbox/rule config is fetched directly from Tools by bearer token, you usually do **not** need to expose the mini PHP web UI publicly at all. In many setups the CLI runner alone is enough.

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
- local message-history preview from `storage/state/message-state.json`
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
- Reply-aware message parsing now strips common quoted history blocks before rule matching and AI summary generation, so follow-up emails in an existing thread can still match the intended support rule.
- The token owner still needs approved `provider_openai` access in Tools unless that user is admin.

### Generic AI fallback when no rule matches

- If a mailbox message has no matching rule, the runner can optionally try one generic AI reply path instead of always ignoring.
- This fallback is gated by config and is disabled unless explicitly enabled through one of these flags:
  - mailbox defaults: `generic_no_match_ai_enabled` (preferred)
  - top-level/settings/features variants from Tools config (`generic_no_match_ai_enabled` / `generic_reply_on_no_match`)
  - env fallback: `MAIL_ASSISTANT_GENERIC_NO_MATCH_AI=1`
- Generic fallback replies are only sent when the AI response looks usable (non-empty, not just a refusal/insufficient-context line).
- If the fallback path is disabled, fails, or returns an unanswerable response, the message remains ignored.

## Notes

- Unmatched mail is left untouched.
- Cron/manual execution only polls unread mail. Already-read mail is skipped immediately.
- Unread mail may be reprocessed on later runs even if the same `Message-Id` already exists in local history; the local state file is now diagnostic history only and is no longer used as a dedupe gate.
- Matchers currently support `from`, `to`, `subject`, and optional body text contains checks.
- Subject matching is now reply-aware (`Re:`, `Fwd:`, `Sv:` prefixes are stripped before rule checks), and outgoing replies now preserve `In-Reply-To` / `References` headers so answers stay in the same thread.
- Unmatched mail is now also logged more explicitly with mailbox/from/to/subject details, which makes `scanned` + `skipped` runs easier to diagnose.
- No-match handling now records clearer state reasons such as `no_matching_rule_generic_ai_disabled`, `no_matching_rule_generic_ai_unanswerable`, `no_matching_rule_generic_ai_error`, and `no_matching_rule_generic_ai_replied`.
- Run summaries now separate `messages_read_skipped` from other skipped categories, so mail that is already marked read at ingest is tracked clearly and does not need to be interpreted as `no_matching_rule` noise.
- Run summaries also expose `messages_previously_recorded_unread` so operators can see when an unread thread was present in local history but was deliberately re-evaluated anyway.
- The runner now parses SpamAssassin headers so heavily flagged messages can be skipped before handling, while wrapper-style SpamAssassin rewrites can still be copied locally and stripped from the body before rule matching/AI.
- Local SpamAssassin/debug copies are written under `storage/cache/message-copies/` when the runner detects a rewritten wrapper or another message worth preserving for review.
- The mini dashboard now shows both the last run summary and the local message-history file so operators can see prior outcomes without that history blocking unread reruns.
- Reply sending now supports multiple transports:
  - `smtp` (default, direct SMTP delivery without requiring local sendmail/postfix)
  - `php_mail` (legacy/local PHP `mail()`)
  - `custom_mta` (pipes RFC822 message to `MAIL_ASSISTANT_MTA_COMMAND`)
  - `tools_api` (relays via `POST /api/mail-support-assistant/send-reply`)
- If local transport fails and `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`, the runner automatically retries through the Tools relay endpoint.
- If `MAIL_ASSISTANT_MAIL_TRANSPORT=tools_api` but `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN` is missing, the runner now skips relay mode and continues with the configured fallback order instead of aborting the whole reply attempt.
- `MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS` can define an explicit ordered fallback chain. If it is left empty, the runner keeps the legacy compatibility behavior where `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true` appends the Tools relay as a fallback.
- Tools relay requires a dedicated personal token (`provider_mail_support_assistant_mailer`) and the `mail-support-assistant.relay` permission for the token owner (admin bypass still applies).


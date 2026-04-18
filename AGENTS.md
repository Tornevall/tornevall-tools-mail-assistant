# AGENTS.md - tornevall-tools-mail-assistant

Project-local guide for `projects/tornevall-tools-mail-assistant`.

Last synchronized: 2026-04-18

## Purpose

This standalone project is the databaseless runtime/client side of the Tools Mail Support Assistant feature.

It must remain a plain PHP project with **no Laravel runtime requirement**.

It is expected to:

- authenticate locally via `.env` for the tiny web UI
- fetch mailbox/rule configuration from Tools
- poll IMAP mailboxes
- decide whether a message should be ignored, replied to statically, or replied to through Tools/OpenAI
- optionally move or delete handled messages
- keep only lightweight local state in `storage/`

## Key files

- `run` - CLI entrypoint and operator help
- `cron-run.sh` - shell wrapper for cron
- `public/index.php` - web entrypoint
- `src/Tools/ToolsApiClient.php` - Tools config + AI HTTP client
- `src/Mail/ImapMailboxClient.php` - IMAP transport wrapper
- `src/Mail/MimeDecoder.php` - subject/body decoding helpers
- `src/Runner/MailAssistantRunner.php` - orchestration logic
- `src/Web/WebApp.php` - env-login dashboard
- `src/Support/MessageStateStore.php` - persisted local message history under `storage/state/message-state.json`

## Current operator behavior

- Cron/manual runs should go through `php run ...`.
- The web dashboard may trigger safe diagnostics/dry-runs through AJAX, but it should still reuse the same runner classes instead of inventing a second execution stack. In many deployments it does not need to be public at all because Tools already hosts the real config surface.
- Mailbox credentials live in Tools admin and are fetched over the bearer-token config endpoint; local storage is limited to `.env`, sessions, logs, last-run summaries, and optional message copies in `storage/`.
- Handled or explicitly ignored mail is still recorded locally by normalized `Message-Id`, but that history is now diagnostic only and must not block later re-evaluation of mail that is still unread in IMAP.
- If `Message-Id` is missing, the runner synthesizes a stable local fallback message key so prior outcomes still appear in `storage/state/message-state.json` for diagnostics.
- SpamAssassin headers should be treated as heuristics only: severe/high-score messages may be skipped, but wrapper-style SpamAssassin rewrites should prefer local copy preservation plus body cleanup over blind skipping.
- Reply chains are now first-class runtime input: subjects are matched without `Re:`/`Fwd:`/`Sv:` prefixes, quoted historical blocks are stripped before body matching/AI summaries, and outgoing replies preserve `In-Reply-To` / `References` headers.
- No-match skips should be logged explicitly with mailbox/from/to/subject metadata so operators can diagnose `scanned` + `skipped` runs without reverse-engineering IMAP content.
- No-match handling can now be config-gated to try one generic AI fallback reply (`generic_no_match_ai_enabled`) before ignoring; if fallback is disabled, unanswerable, or fails, the message remains ignored with a specific reason code.
- Generic no-match outcome reasons should stay explicit in state/logs: `no_matching_rule_generic_ai_disabled`, `no_matching_rule_generic_ai_unanswerable`, `no_matching_rule_generic_ai_error`, `no_matching_rule_generic_ai_replied`.
- Runner summaries now keep `messages_read_skipped` as a dedicated category; mail that is already seen at ingest should not be mixed into `no_matching_rule` diagnostics.
- Runner summaries may also report `messages_previously_recorded_unread` when an unread thread existed in local history but was intentionally re-checked.

## Runtime assumptions

- Real mailbox work requires `ext-imap`.
- Missing `ext-imap` must fail clearly instead of crashing unclearly.
- Dry-run must never send replies or mutate mailboxes.
- No-match mails must stay untouched.
- The same personal token is used both to fetch config and, when enabled, to call Tools-hosted AI.
- Outgoing replies now support `php_mail`, `custom_mta`, and `tools_api` transports (`MAIL_ASSISTANT_MAIL_TRANSPORT`).
- If local delivery fails and `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`, the runner may retry through `POST /api/mail-support-assistant/send-reply` using `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`.
- Tools relay tokens are expected to be dedicated personal keys (provider `provider_mail_support_assistant_mailer`) and should be permission-gated server-side (`mail-support-assistant.relay`).

## Validation checklist

After edits, run at minimum:

```bash
php -l run
php -l public/index.php
php -l src/Tools/ToolsApiClient.php
php -l src/Mail/ImapMailboxClient.php
php -l src/Mail/MimeDecoder.php
php -l src/Support/MessageStateStore.php
php -l src/Runner/MailAssistantRunner.php
php -l src/Web/WebApp.php
php run --help
php run --self-test
```


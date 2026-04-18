n# AGENTS.md - tornevall-tools-mail-assistant

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
- The web dashboard may trigger safe diagnostics/dry-runs through AJAX, but it should still reuse the same runner
  classes instead of inventing a second execution stack. In many deployments it does not need to be public at all
  because Tools already hosts the real config surface.
- Mailbox credentials live in Tools admin and are fetched over the bearer-token config endpoint; local storage is
  limited to `.env`, sessions, logs, last-run summaries, and optional message copies in `storage/`.
- Handled or explicitly ignored mail is still recorded locally by normalized `Message-Id`, but that history is now
  diagnostic only and must not block later re-evaluation of mail that is still unread in IMAP.
- If `Message-Id` is missing, the runner synthesizes a stable local fallback message key so prior outcomes still appear
  in `storage/state/message-state.json` for diagnostics.
- SpamAssassin headers should be treated as heuristics only: severe/high-score messages may be skipped, but
  wrapper-style SpamAssassin rewrites should prefer local copy preservation plus body cleanup over blind skipping.
- Reply chains are now first-class runtime input: subjects are matched without `Re:`/`Fwd:`/`Sv:` prefixes, quoted
  historical blocks are stripped before body matching/AI summaries, and outgoing replies preserve `In-Reply-To` /
  `References` headers.
- Outgoing replies are now composed as `multipart/alternative`: keep the plain-text reply body, but also derive a
  styled HTML body so ordinary mail clients see a formatted support reply instead of raw plain text.
- No-match skips should be logged explicitly with mailbox/from/to/subject metadata so operators can diagnose `scanned` +
  `skipped` runs without reverse-engineering IMAP content.
- Rule collisions are now first-class diagnostics too: the runner should evaluate all matching rules, choose the most
  specific winner deterministically, and record both the winning rule and the competing matches so operators can see why
  one rule won over another.
- No-match handling can now be config-gated to try one generic AI fallback reply (`generic_no_match_ai_enabled`) before
  ignoring; if fallback is disabled, unanswerable, or fails, the message remains ignored with a specific reason code.
- Generic no-match outcome reasons should stay explicit in state/logs: `no_matching_rule_generic_ai_disabled`,
  `no_matching_rule_generic_ai_unanswerable`, `no_matching_rule_generic_ai_error`,
  `no_matching_rule_generic_ai_replied`.
- Runner summaries now keep `messages_read_skipped` as a dedicated category; mail that is already seen at ingest should
  not be mixed into `no_matching_rule` diagnostics.
- Runner summaries may also report `messages_previously_recorded_unread` when an unread thread existed in local history
  but was intentionally re-checked.

## Runtime assumptions

- Real mailbox work requires `ext-imap`.
- Missing `ext-imap` must fail clearly instead of crashing unclearly.
- Dry-run must never send replies or mutate mailboxes.
- No-match mails must stay untouched.
- The same personal token is used both to fetch config and, when enabled, to call Tools-hosted AI.
- For matched rules with `ai_enabled=true`, the standalone client must treat the rule's responder/persona/custom instruction/model/reasoning values as authoritative AI override inputs for that single Tools AI request.
- Temporary AI throttle failures (for example `429 / Too Many Attempts`) should be retried before the standalone client gives up on a matched rule's AI reply.
- If an AI-enabled matched rule still fails after retries and no explicit `template_text` fallback is configured, do not send the old generic canned sentence; abort/log that reply instead of pretending the issue was handled.
- Mailbox-level `generic_no_match_*` AI settings are only for the unmatched-mail fallback path and must not replace matched rule AI overrides.
- Outgoing replies now support `smtp` (default), `php_mail`, `custom_mta`, and `tools_api` transports (
  `MAIL_ASSISTANT_MAIL_TRANSPORT`).
- SMTP runtime keys are `MAIL_ASSISTANT_SMTP_HOST`, `MAIL_ASSISTANT_SMTP_PORT`, `MAIL_ASSISTANT_SMTP_SECURITY`,
  `MAIL_ASSISTANT_SMTP_USERNAME`, `MAIL_ASSISTANT_SMTP_PASSWORD`, `MAIL_ASSISTANT_SMTP_EHLO`,
  `MAIL_ASSISTANT_SMTP_TIMEOUT`, and optional `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE`.
- If local delivery fails and `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`, the runner may retry through
  `POST /api/mail-support-assistant/send-reply` using `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`.
- Tools relay payloads may now include additive `body_html`; when present, relay delivery should preserve the same
  multipart plain-text + HTML reply body instead of downgrading to text only.
- Tools relay tokens are expected to be dedicated personal keys (provider `provider_mail_support_assistant_mailer`) and
  should be permission-gated server-side (`mail-support-assistant.relay`).
- Mailbox config may now also expose `generic_no_match_ai_reasoning_effort`, and matched rules may now expose `ai_reasoning_effort`.

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


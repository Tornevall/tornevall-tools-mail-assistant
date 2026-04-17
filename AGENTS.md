# AGENTS.md - tornevall-tools-mail-assistant

Project-local guide for `projects/tornevall-tools-mail-assistant`.

Last synchronized: 2026-04-17

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
- `src/Support/MessageStateStore.php` - persisted handled/ignored `Message-Id` state under `storage/state/message-state.json`

## Current operator behavior

- Cron/manual runs should go through `php run ...`.
- The web dashboard may trigger safe diagnostics/dry-runs through AJAX, but it should still reuse the same runner classes instead of inventing a second execution stack.
- Mailbox credentials live in Tools admin and are fetched over the bearer-token config endpoint; local storage is limited to `.env`, sessions, logs, last-run summaries, and optional message copies in `storage/`.
- Handled or explicitly ignored mail is also recorded locally by normalized `Message-Id` so unread leftovers are skipped safely on later cron runs.
- SpamAssassin headers should be treated as heuristics only: severe/high-score messages may be skipped, but wrapper-style SpamAssassin rewrites should prefer local copy preservation plus body cleanup over blind skipping.

## Runtime assumptions

- Real mailbox work requires `ext-imap`.
- Missing `ext-imap` must fail clearly instead of crashing unclearly.
- Dry-run must never send replies or mutate mailboxes.
- No-match mails must stay untouched.
- The same personal token is used both to fetch config and, when enabled, to call Tools-hosted AI.

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


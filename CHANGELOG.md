# CHANGELOG

## 0.3.0 - 2026-04-17

- AI reply generation now defaults to a primary model (`gpt-5.4`) and retries once with a fallback model (`gpt-4o-mini`) when the primary request fails.
- Reasoning effort is now configurable (`MAIL_ASSISTANT_AI_REASONING_EFFORT`) and is forwarded on both primary and fallback requests.
- AI context preparation now strips HTML/MIME boundary noise from incoming message text so summaries stay focused on the actual user question/content.

## 0.2.0 - 2026-04-17

- Added local `Message-Id` persistence under `storage/state/message-state.json` so handled or explicitly ignored mail is not reprocessed if it remains unread in IMAP.
- Runner summaries and the mini dashboard now expose the local message-state overview alongside the last-run summary.
- Renamed/documented the standalone project under `projects/tornevall-tools-mail-assistant`.
- Clarified that the project stays plain PHP and databaseless locally; mailbox credentials remain managed in Tools admin.
- Added AJAX dashboard actions for refresh, self-test, and safe dry-run execution without page reloads.
- Added SpamAssassin header parsing, wrapper stripping, high-score skip heuristics, and optional local message-copy preservation under `storage/cache/message-copies/`.
- Added richer runner summaries for SpamAssassin-driven skips and saved copies.

## 0.1.0 - 2026-04-17

- Initial standalone project scaffold for the Mail Support Assistant.
- Added env-driven mini web UI with local login and Tools config preview.
- Added CLI runner skeleton with `--dry-run`, `--mailbox`, `--limit`, and `--self-test` support.
- Added Tools API integration for `GET /api/mail-support-assistant/config` and `POST /api/ai/socialgpt/respond`.
- Added IMAP polling, MIME decoding, rule matching, and reply orchestration scaffolding with safe dry-run behavior.


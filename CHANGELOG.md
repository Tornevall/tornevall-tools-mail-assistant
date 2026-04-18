# CHANGELOG

## 0.3.13 - 2026-04-18

- Outgoing replies are now emitted as `multipart/alternative` across standalone transports, with a plain-text fallback plus a styled HTML version for better-looking support mail.
- Tools relay payloads from the standalone client now also include additive `body_html`, so fallback relay delivery keeps the same formatted reply body instead of downgrading to plain text only.

## 0.3.12 - 2026-04-18

- AI-enabled matched rules now forward `responder_name`, `persona_profile`, `custom_instruction`, `ai_model`, and `ai_reasoning_effort` to Tools as explicit one-request overrides instead of only looking like generic/static replies.
- Mailbox-level unmatched-mail AI config can now also carry `generic_no_match_ai_reasoning_effort` from Tools config.

## 0.3.11 - 2026-04-18

- Messages skipped because no rule matches, or because the generic no-match fallback is disabled/unanswerable/failing, now stay unread instead of being marked seen.
- `mark_seen_on_skip` is now only honored for explicit heuristic skips such as high-score SpamAssassin junk, so misconfigured mailbox rules are easier to revisit.

## 0.3.10 - 2026-04-18

- SMTP delivery now treats blank `MAIL_ASSISTANT_SMTP_SECURITY`, `MAIL_ASSISTANT_SMTP_EHLO`, and `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE` as optional overrides instead of invalid configuration.
- Empty `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE` now falls back to the reply `From:` address automatically, which makes ordinary host/port/user/pass SMTP setups work without extra envelope configuration.

## 0.3.9 - 2026-04-18

- Outgoing mail transport now supports an explicit ordered fallback chain through `MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS`.
- If primary transport is `tools_api` but `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN` is missing, the runner now skips relay mode cleanly and continues with SMTP/local fallback transports instead of failing the whole reply attempt.
- Legacy `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true` behavior is still supported and now acts as a compatibility shortcut when no explicit fallback chain is configured.

## 0.3.8 - 2026-04-18

- `message_state` summary now exposes `count_pending` and `count_already_replied` so it is immediately clear how many
  messages have been replied to vs still need attention — instead of only showing `handled: N` which was ambiguous.
- Message state reason changed from `rule_matched` to `rule_matched_replied` to make it explicit that a reply was sent.

## 0.3.7 - 2026-04-18

- Added storage cleanup feature: purge log, last-run summary, message state, and saved message copies in one operation.
- `MailAssistantRunner::cleanup()` orchestrates all purge targets with granular on/off flags.
- `Logger::purgeLog()` and `Logger::purgeLastRun()` clear the log file and last-run JSON.
- `MessageStateStore::purge()` resets the message-state JSON while keeping the file structure intact.
- CLI: `php run --cleanup` purges log + last-run + state; `--cleanup-copies` also deletes saved message copies.
- Dashboard: new red **🗑 Cleanup storage** button opens a modal where each target (log, last-run, state, copies) can be toggled before confirming the purge.

## 0.3.6 - 2026-04-18

- `message_state` summary `recent` list now only shows messages that are NOT yet handled (status ≠ `handled`).
  Already-handled messages are still counted in `status_counts` and `count`, but are excluded from the `recent` view
  so the dashboard only surfaces messages that still need attention.

## 0.3.5 - 2026-04-18

- Added `pickup` transport mode: writes a properly-formatted RFC 2822 message file to a local MTA spool/pickup
  directory (`MAIL_ASSISTANT_PICKUP_DIR`, e.g. `/var/spool/postfix/maildrop`). No sendmail or command invocation
  needed; the MTA pickup daemon collects the file automatically.
- Removed all sendmail references: `custom_mta` default command is now empty and requires explicit configuration in
  `MAIL_ASSISTANT_MTA_COMMAND`; the example comment now references `postdrop` instead of `sendmail`.
- Synced `.env` and `.env.example` so both cover identical keys in the same section order — `.env.example` keeps safe
  placeholder values while `.env` holds the real deployment values.
- Added `MAIL_ASSISTANT_PICKUP_DIR` to both `.env` and `.env.example`.

## 0.3.4 - 2026-04-18

- Added first-class SMTP transport support in `MailAssistantRunner` (supports `none|tls|ssl`, optional AUTH LOGIN,
  configurable timeout/EHLO/envelope-from).
- Changed default outgoing transport from `php_mail` to `smtp` so standalone runs no longer depend on local
  Postfix/sendmail by default.
- Added SMTP environment keys in both `.env` and `.env.example` (`MAIL_ASSISTANT_SMTP_*`).
- Kept `php_mail`, `custom_mta`, and `tools_api` transports available, with existing fallback-to-Tools behavior intact.

## 0.3.3 - 2026-04-18

- Fixed `Array to string conversion` warnings in `ToolsApiClient` error handling by normalizing array-style API messages
  into readable strings.
- Added env-driven outgoing mail transport selection in the runner: `php_mail`, `custom_mta`, and `tools_api`.
- Added optional custom MTA command support via `MAIL_ASSISTANT_MTA_COMMAND` when
  `MAIL_ASSISTANT_MAIL_TRANSPORT=custom_mta`.
- Added optional fallback from local transport failures to Tools mail relay via
  `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`.
- Added dedicated relay token env support (`MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`) for
  `POST /api/mail-support-assistant/send-reply`.

## 0.3.2 - 2026-04-18

- Local `message-state.json` is now diagnostic history only and no longer blocks unread mail from being re-evaluated on
  later runs.
- Already-read IMAP mail is still skipped immediately, but unread messages that were previously handled or ignored can
  now match newly added rules without clearing local state first.
- Runner summaries now expose `messages_previously_recorded_unread` / mailbox `previously_recorded_unread` so operators
  can see when an unread message was found in local history and intentionally re-checked.
- README and mini dashboard wording now make it explicit that the standalone web UI is optional and that the local
  message-history file is non-blocking.

## 0.3.1 - 2026-04-18

- Added explicit `messages_read_skipped` / `read_skipped` counters in runner summaries so already-read mail is visible
  as its own category instead of blending with no-match skips.
- IMAP message payloads now include an `is_seen` flag, and the runner now skips messages that are already marked read at
  ingest without recording them as `no_matching_rule` ignored events.
- Local message-state summaries now expose `excluded_read_records` and `raw_count` metadata, while default `recent`
  output hides records marked with reason `already_read_at_ingest`.

## 0.3.0 - 2026-04-17

- Added a config-gated generic AI fallback path for `no_matching_rule` emails, so unmatched support mail can still get a
  helpful reply when enabled instead of always being ignored.
- Added answerability checks for generic fallback replies; empty/refusal-style outputs are now treated as unanswerable
  and remain ignored.
- Added explicit no-match state reasons (`no_matching_rule_generic_ai_disabled`,
  `no_matching_rule_generic_ai_unanswerable`, `no_matching_rule_generic_ai_error`,
  `no_matching_rule_generic_ai_replied`) to improve operator diagnostics.
- AI reply generation now defaults to a primary model (`gpt-5.4`) and retries once with a fallback model (`gpt-4o-mini`)
  when the primary request fails.
- Reasoning effort is now configurable (`MAIL_ASSISTANT_AI_REASONING_EFFORT`) and is forwarded on both primary and
  fallback requests.
- AI context preparation now strips HTML/MIME boundary noise from incoming message text so summaries stay focused on the
  actual user question/content.
- Reply-chain handling is now stronger: normalized subjects strip `Re:`/`Fwd:`/`Sv:` prefixes before rule matching,
  quoted historical mail blocks are stripped from the body before matching/AI summaries, and outgoing replies now
  preserve `In-Reply-To` / `References` headers.
- IMAP message parsing now stores real `message_id` values plus a stable synthesized fallback `message_key` when the
  header is missing, so skipped/handled mail finally appears in local message-state summaries.
- No-match skips are now logged more explicitly with mailbox/from/to/subject context to make `scanned` but `handled=0`
  runs easier to debug.

## 0.2.0 - 2026-04-17

- Added local `Message-Id` persistence under `storage/state/message-state.json` so handled or explicitly ignored mail is
  not reprocessed if it remains unread in IMAP.
- Runner summaries and the mini dashboard now expose the local message-state overview alongside the last-run summary.
- Renamed/documented the standalone project under `projects/tornevall-tools-mail-assistant`.
- Clarified that the project stays plain PHP and databaseless locally; mailbox credentials remain managed in Tools
  admin.
- Added AJAX dashboard actions for refresh, self-test, and safe dry-run execution without page reloads.
- Added SpamAssassin header parsing, wrapper stripping, high-score skip heuristics, and optional local message-copy
  preservation under `storage/cache/message-copies/`.
- Added richer runner summaries for SpamAssassin-driven skips and saved copies.

## 0.1.0 - 2026-04-17

- Initial standalone project scaffold for the Mail Support Assistant.
- Added env-driven mini web UI with local login and Tools config preview.
- Added CLI runner skeleton with `--dry-run`, `--mailbox`, `--limit`, and `--self-test` support.
- Added Tools API integration for `GET /api/mail-support-assistant/config` and `POST /api/ai/socialgpt/respond`.
- Added IMAP polling, MIME decoding, rule matching, and reply orchestration scaffolding with safe dry-run behavior.


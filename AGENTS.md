# AGENTS.md - tornevall-tools-mail-assistant

Project-local guide for `projects/tornevall-tools-mail-assistant`.

Last synchronized: 2026-04-20

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
- `src/Support/MessageStateStore.php` - optional local message-history storage under `storage/state/message-state.json` when history mode is requested
- `tests/generic-no-match-json-regression.php` - strict JSON allow/deny parsing coverage for unmatched-mail AI
- `tests/generic-no-match-runner-regression.php` - runner-level guard that rejected unmatched-mail AI decisions stay unread
- `tests/bcc-routing-regression.php` - verifies normalized `to` / `cc` / `bcc` relay recipient forwarding
- `tests/default-bcc-env-regression.php` - verifies `.env` fallback BCC behavior when config BCC values are empty
- `tests/reply-disabled-unread-regression.php` - verifies matched rules with `reply.enabled=false` stay unread by default
- `tests/ai-instruction-compliance-regression.php` - verifies obviously contradictory AI replies are rejected against strict redirect/no-responsibility instructions
- `tests/body-only-no-summary-regression.php` - verifies `write only the email body` instructions suppress the appended request-summary block
- `tests/reply-chain-rule-reuse-regression.php` - verifies a follow-up reply can reuse the earlier matched rule from local thread-linked history
- `tests/reply-chain-generic-no-match-reuse-regression.php` - verifies a follow-up reply can prioritize the earlier unmatched fallback row from the same reply chain
- `tests/reply-chain-subject-fallback-regression.php` - verifies older/malformed follow-ups can still reuse the earlier selected rule through normalized subject + same participants when reply headers are missing
- `tests/reply-chain-reply-message-id-regression.php` - verifies handled replies persist a generated outgoing `reply_message_id` and that later follow-ups can reuse the earlier rule through that stored sent-message id
- `tests/reply-chain-generic-no-match-bypass-regression.php` - verifies explicitly linked follow-ups in an already approved unmatched thread can continue directly on the previously used unmatched row without re-running the initial allow-condition triage

## Release and parity discipline

- **Every assistant change must be committed immediately in this repo.** Do not leave Mail Support Assistant feature/code/doc/test changes uncommitted between sessions.
- **Every incremental assistant version must be tagged and pushed.** Use the next semantic standalone tag (`0.x.y`) for each committed assistant increment, and backfill missing tags when older changelog versions were never tagged.
- `CHANGELOG.md` must always be updated before creating that incremental version tag, and `README.md` must be kept in sync when operator behavior, setup, or workflows change.
- If a future Tools-hosted mail-client/operator surface is added under Tools admin, any shared mail-client behavior change (message cards, diagnostics, thread continuity, manual handling flow, reply transport behavior, etc.) must update **both** the standalone UI and the Tools-admin UI in the same change.
- Treat the standalone dashboard (`templates/dashboard.php`, `src/Web/WebApp.php`) and any future Tools-admin mail-client surface as one shared operator contract; keep both AGENTS files synchronized when that contract changes.

## Current operator behavior

- Cron/manual runs should go through `php run ...`.
- The web dashboard may trigger safe diagnostics/dry-runs through AJAX, but it should still reuse the same runner
  classes instead of inventing a second execution stack. In many deployments it does not need to be public at all
  because Tools already hosts the real config surface.
- The standalone dashboard should now prefer a human-readable operator inbox over raw JSON dumps: mailbox/message cards, expandable diagnostics, optional local-header visibility from saved message copies, and lightweight continuity inspection. It is still **not** the place where the full mailbox/rule admin model should be duplicated; keep heavy admin/config in Tools.
- Mailbox credentials live in Tools admin and are fetched over the bearer-token config endpoint; local storage is
  limited to `.env`, sessions, logs, last-run summaries, and optional message copies in `storage/`.
- Handled or explicitly ignored mail may still be recorded locally by normalized `Message-Id`, but only when history
  mode is explicitly requested (for example `php run --include-history`).
- That history is diagnostic only and must never block later re-evaluation of mail that is still unread in IMAP.
- If `Message-Id` is missing, the runner synthesizes a stable local fallback message key so prior outcomes can still be
  stored in `storage/state/message-state.json` when history mode is enabled.
- SpamAssassin headers should be treated as heuristics only: severe/high-score messages may be skipped, but
  wrapper-style SpamAssassin rewrites should prefer local copy preservation plus body cleanup over blind skipping.
- Reply chains are now first-class runtime input: subjects are matched without `Re:`/`Fwd:`/`Sv:` prefixes, quoted
  historical blocks are stripped before body matching/AI summaries, and outgoing replies preserve `In-Reply-To` /
  `References` headers.
- Reply-chain continuity is now also rule-aware: when `In-Reply-To` / `References` link a new unread message to an earlier handled conversation, the runner may reuse the earlier matched rule or prioritize the earlier unmatched fallback row before it gives up as no-match.
- Reply continuity now also has two extra safety nets: locally sent replies generate/store an explicit outgoing `reply_message_id`, and older/malformed follow-ups without usable reply headers may still recover continuity through normalized subject + same participants (`from` / `to`).
- When a reply chain is explicitly linked to a previously approved unmatched thread, the runner may now continue that same unmatched row directly instead of re-running the initial allow-condition classifier for the same conversation.
- Outgoing replies are now composed as `multipart/alternative`: keep the plain-text reply body, but also derive a
  styled HTML body so ordinary mail clients see a formatted support reply instead of raw plain text.
- No-match skips should be logged explicitly with mailbox/from/to/subject metadata so operators can diagnose `scanned` +
  `skipped` runs without reverse-engineering IMAP content.
- Rule collisions are now first-class diagnostics too: the runner should evaluate all matching rules, choose the winner
  deterministically, and record both the winning rule and the competing matches so operators can see why one rule won
  over another.
- Winner selection order is now: lower `sort_order` first (explicit operator priority), then more contextual match
  types (`subject` / `body` before `to` / `from`), then more active match criteria, then longer combined match text.

## Implementation hints

- Real mailbox work requires `ext-imap`.
- Missing `ext-imap` must fail clearly instead of crashing unclearly.
- Dry-run must never send replies or mutate mailboxes.
- No-match mails must stay untouched.
- The same personal token is used both to fetch config and, when enabled, to call Tools-hosted AI.
- For matched rules with `ai_enabled=true`, the standalone client must treat the rule's responder/persona/custom instruction/model/reasoning values as authoritative AI override inputs for that single Tools AI request.
- If a matched rule instruction explicitly requires a language such as English, the standalone client should convert that
  into a real `response_language` override rather than hoping the model infers it from prose alone.
- If a footer/signature is configured locally, AI prompts should tell the model not to invent a second closing/signature
  block on its own.
- For critical redirect/disclaimer instructions, the standalone client should also validate the returned AI text locally
  before send and reject replies that obviously violate explicit language requirements, omit required redirect addresses,
  skip mandatory “must state” facts, or wrongly claim responsibility/handling.
- If the instruction explicitly says to write only the email body, the standalone client should not append its usual
  original-request summary block afterward.
- Temporary AI throttle failures (for example `429 / Too Many Attempts`) should be retried before the standalone client gives up on a matched rule's AI reply.
- Empty AI responses should also trigger the fallback model path; fallback is not only for thrown HTTP/API exceptions.
- If an AI-enabled matched rule still fails after retries and no explicit `template_text` fallback is configured, do not send the old generic canned sentence; abort/log that reply instead of pretending the issue was handled.
- If a rule matches but no outgoing reply is actually sent, the default-safe behavior is to leave the message unread and
  untouched unless the config explicitly opts into post-handle mutation without reply.
- Mailbox-level `generic_no_match_*` AI settings are only for the unmatched-mail fallback path and must not replace matched rule AI overrides.
- Unmatched fallback rows must continue in `sort_order` even if one row is rejected or hits a row-local AI/API evaluation failure; only a real sent reply should stop that unmatched-row loop early.
- Outer SpamAssassin wrapper prose may be ignored for unmatched-mail AI classification, but SpamAssassin score/tests should still be available as safety/risk hints.
- Outgoing replies now support `smtp` (default), `php_mail`, `custom_mta`, and `tools_api` transports (
  `MAIL_ASSISTANT_MAIL_TRANSPORT`).
- If neither the matched rule nor the mailbox defaults define a BCC recipient, the standalone runtime may now fall back to `MAIL_ASSISTANT_DEFAULT_BCC` from `.env`.
- SMTP runtime keys are `MAIL_ASSISTANT_SMTP_HOST`, `MAIL_ASSISTANT_SMTP_PORT`, `MAIL_ASSISTANT_SMTP_SECURITY`,
  `MAIL_ASSISTANT_SMTP_USERNAME`, `MAIL_ASSISTANT_SMTP_PASSWORD`, `MAIL_ASSISTANT_SMTP_EHLO`,
  `MAIL_ASSISTANT_SMTP_TIMEOUT`, and optional `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE`.
- If local delivery fails and `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`, the runner may retry through
  `POST /api/mail-support-assistant/send-reply` using `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`.
- Tools relay payloads may now include additive `body_html`; when present, relay delivery should preserve the same
  multipart plain-text + HTML reply body instead of downgrading to text only.
- CLI/manual runs now mirror logger output to stdout by default, so `php run` / `cron-run.sh` should show live progress
  while still saving the persistent log file.
- Run summaries should include per-message `message_results[]` diagnostics, not only aggregate counters.
- No-match diagnostics should retain an `evaluated_no_match_rules[]` trace so operators can see which unmatched rows were actually tried and why each one rejected/failed.
- The local message-state file is still not allowed to suppress unread reprocessing, but it may now be used as a continuity hint to recover the prior selected rule / prior matched unmatched-row for reply-chain follow-ups.
- If reply transport succeeds but IMAP post-handle finalize (`markSeen`, move, delete) fails, the run should surface an
  explicit warning reason such as `rule_matched_replied_imap_finalize_failed` instead of silently pretending the whole
  mailbox mutation finished.
- Outgoing HTML mail should keep explicit dark text colors on the main wrapper and quoted request excerpt so manual
  replies in mail clients do not degrade into white text on white background.
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

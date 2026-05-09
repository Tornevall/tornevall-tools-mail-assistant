# AGENTS.md - tornevall-tools-mail-assistant

Project-local guide for `projects/tornevall-tools-mail-assistant`.

Last synchronized: 2026-05-09

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
- `tests/tools-case-sync-regression.php` - verifies processed standalone message outcomes are synced back into the new Tools-side support case store
- `tests/tools-case-sync-request-regression.php` - verifies Tools case-sync payloads are UTF-8-safe, trimmed to the live API validation limits, strip empty selected-rule placeholders like `selected_rule_id=0`, and keep field-level 422 validation details in the standalone warning text
- `tests/unanswered-report-regression.php` - verifies the optional unanswered-message summary report is emitted when enabled and pending messages exist
- `src/Mail/ImapMailboxClient.php` - IMAP transport wrapper
- `src/Mail/MimeDecoder.php` - subject/body decoding helpers
- `src/Runner/MailAssistantRunner.php` - orchestration logic
- `src/Web/WebApp.php` - env-login dashboard
- `src/Support/MarkdownRenderer.php` - safe markdown-to-HTML renderer used by outgoing styled reply bodies
- `src/Support/RunLock.php` - non-blocking local lock file helper for overlap-safe cron/dashboard runs
- `tests/cron-script-lock-regression.php` - verifies `cron-run.sh` blocks overlapping cron invocations through its PID-aware shell lock and recovers from stale lock holders
- `tests/markdown-html-reply-regression.php` - verifies outgoing styled reply HTML converts markdown structure into real HTML instead of leaving raw markdown markers visible
- `tests/run-lock-regression.php` - verifies a second runner invocation is rejected while another process already holds the run lock
- `tests/subject-issue-id-regression.php` - verifies outgoing reply subjects reuse one stable issue-id tag instead of appending a new one on every reply
- `tests/dashboard-config-visibility-regression.php` - verifies the dashboard still shows config-only mailbox cards before any saved run exists and that readable matched-rule / unmatched-row AI fields stay exposed in the config summary
- `tests/manual-reply-regression.php` - verifies operator-triggered manual replies reuse the same styled reply/transport pipeline and persist the chosen local rule assignment
- `tests/quota-alert-regression.php` - verifies quota/billing failures become explicit runtime alerts and stop endless unmatched retries by marking the message seen for manual follow-up
- `src/Support/MessageStateStore.php` - optional local message-history storage under `storage/state/message-state.json` when history mode is requested
- `tests/generic-no-match-json-regression.php` - strict JSON allow/deny parsing coverage for unmatched-mail AI
  - `tests/generic-no-match-final-fallback-regression.php` - verifies the mailbox-owned last unmatched fallback runs after advanced rows and marks handled messages seen after reply
  - `tests/generic-no-match-rows-regression.php` - verifies ordered unmatched-row fallthrough, row-local failure tolerance, and the final all-rows-reject unread path
- `tests/bcc-routing-regression.php` - verifies normalized `to` / `cc` / `bcc` relay recipient forwarding
- `tests/default-bcc-env-regression.php` - verifies `.env` fallback BCC behavior when config BCC values are empty
- `tests/reply-disabled-unread-regression.php` - verifies matched rules with `reply.enabled=false` stay unread by default
- `tests/ai-instruction-compliance-regression.php` - verifies obviously contradictory AI replies are rejected against strict redirect/no-responsibility instructions
- `tests/body-only-no-summary-regression.php` - verifies `write only the email body` instructions suppress the appended request-summary block
- `tests/contact-form-summary-regression.php` - verifies clear-text contact-form mails with `From:` / `Subject:` / `Sender IP:` / `Message Body:` lines still preserve the real issue text in summaries and appended reply excerpts
- `tests/html-body-decoding-regression.php` - verifies HTML-only inbound mail is decoded into usable body text for AI context and `body_contains` rule matching
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
- That operator inbox can now also take care of latest-run mail directly: operators may assign a local rule context, send a manual reply through the same styled outbound pipeline as automatic replies, or mark a message handled/read so the unread poller stops retrying it.
- The dashboard's activity tab should still list configured mailboxes even before any dry-run/real run has produced message cards, while clearly stating that this surface shows latest-run activity rather than a full live IMAP mail client.
- The standalone dashboard may now also merge a lightweight live unread IMAP preview into that activity tab, so operators can act on fresh unread mail even before another saved run exists.
- The standalone runtime can now sync processed inbox outcomes back into Tools as threaded support cases, and outgoing replies may append a public case-tracking link for the recipient when that Tools sync succeeds in time.
- Every unread, non-assistant, not-already-seen mailbox message should now be reported to Tools immediately when it is discovered, before later reply/no-match handling decides the final outcome, so admins can still build rules or trigger manual AI follow-up from the centralized Tools GUI afterwards.
- That same centralized Tools GUI can now also finish the follow-up itself: the threaded Tools case page may generate a draft, send the reply through Tools, or mark the case handled without an outbound mail, so the standalone dashboard is no longer the only operator surface for those follow-up threads.
- When a skipped / unmatched / `reply not sent` message has been handed over successfully into Tools as a centralized `needs_attention` case, the standalone runner may now mark the IMAP message as seen so the same unread mail does not keep reappearing while the real follow-up is already happening in Tools.
- That centralized Tools case history can now also carry full inbound/outbound body content plus source-instance metadata, so shared operator review still works when the cronjob runs on a different server.
- The same Tools case sync is now expected to push handled, ignored, manual-reply, and manual-marked mail even when the message lacks a stable local message-state key, and those synced case entries should carry raw inbound headers plus raw/plain/HTML body variants so Tools can behave more like a remote mail client.
- The standalone Tools case-sync transport should now also sanitize broken/non-UTF8 mailbox strings, trim oversized sync fields down to the public API limits before sending them, strip empty selected-rule placeholders like `selected_rule_id=0` when no rule matched yet, and log field-level validation details from Tools when one sync still fails.
- Optional operator reporting for unanswered messages is now env-controlled (`MAIL_ASSISTANT_UNANSWERED_REPORT_ENABLED` / `MAIL_ASSISTANT_UNANSWERED_REPORT_TO`) and should summarize skipped/error/no-reply items after a run without interrupting the run itself when the report mail fails.
- CLI/dry-run runs must now refuse to start when another process already holds the same assistant instance's local run lock; overlapping cron invocations should skip cleanly instead of double-processing unread mail.
- `cron-run.sh` should also block overlapping wrapper-level cron starts before PHP begins, using a PID-aware shell lock with stale-lock cleanup so operators can see which process currently owns the cron wrapper lock.
- The dashboard's config tab should keep readable matched-rule rows, fallback-rule details, and unmatched AI/IF rows visible so operators do not have to reverse-engineer the raw JSON to understand what Tools actually sent to the standalone runner.
- Runtime alert banners should stay prominent for AI quota/billing failures and Tools-side daily AI budget exhaustion/low-budget states when that metadata is present in config or the latest run summary.
- Mailbox credentials live in Tools admin and are fetched over the bearer-token config endpoint; local storage is
  limited to `.env`, sessions, logs, last-run summaries, and optional message copies in `storage/`.
- The runner should refresh one stable local message copy per scanned mail so the dashboard/manual handling flow keeps a readable body preview even when the latest-run summary itself only stores shorter excerpts.
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
- Reply-aware parsing must not treat contact-form style body lines (`From:` / `Subject:` / `Sender IP:` / `Message Body:` inside the plain-text body itself) as automatic quoted-history cutoffs; those lines may be the only path to the actual problem description and sender IP.
- HTML-only or non-UTF8 inbound mail must not silently degrade into subject-only handling; MIME decoding should extract charset-aware plain text from HTML bodies before rule matching, AI context building, or appended request-summary generation runs.
- Reply-chain continuity is now also rule-aware: when `In-Reply-To` / `References` link a new unread message to an earlier handled conversation, the runner may reuse the earlier matched rule or prioritize the earlier unmatched fallback row before it gives up as no-match.
- Reply continuity now also has two extra safety nets: locally sent replies generate/store an explicit outgoing `reply_message_id`, and older/malformed follow-ups without usable reply headers may still recover continuity through normalized subject + same participants (`from` / `to`).
- Outgoing replies may now also stamp one stable subject issue-id tag (default format like `[Ärende MSA-ABC12345]`), and later replies in the same thread should reuse that stored tag rather than appending a fresh one every time.
- When a reply chain is explicitly linked to a previously approved unmatched thread, the runner may now continue that same unmatched row directly instead of re-running the initial allow-condition classifier for the same conversation.
- Outgoing replies are now composed as `multipart/alternative`: keep the plain-text reply body, but also derive a
  styled HTML body so ordinary mail clients see a formatted support reply instead of raw plain text.
- That styled HTML body should now render normal markdown structure from AI/operator replies into real HTML blocks (headings, lists, links, emphasis, inline code) rather than exposing raw markdown markers in the visible mail body.
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
- Tools API and SMTP TLS verification may now be overridden separately through `.env` (`MAIL_ASSISTANT_TOOLS_SSL_VERIFY` / `MAIL_ASSISTANT_TOOLS_CA_BUNDLE`, `MAIL_ASSISTANT_SMTP_SSL_VERIFY` / `MAIL_ASSISTANT_SMTP_CA_FILE`) for WSL/self-signed recovery cases, but verification should remain on by default.
- No-match mails that never enter strict unmatched AI evaluation should stay untouched/unread, but terminal unmatched reject/error outcomes after actual evaluation may now be marked seen for manual follow-up so the unread poller stops retrying them forever.
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
- The unmatched-mail fallback must now be enabled only by the mailbox checkbox coming from Tools config (`generic_no_match_ai_enabled`); env-only toggles must not silently turn it on anymore.
- The unmatched-mail JSON parser may be strict about final safety decisions, but it should still tolerate small provider-formatting mistakes such as smart quotes or trailing commas before it concludes that the reply was malformed JSON.
- If that checkbox is off, the standalone runner should not even materialize/evaluate advanced unmatched rows or the mailbox-level last fallback, even when those text fields still contain older saved values.
- Ordered `generic_no_match_rules[]` rows are still the advanced unmatched checks, but the mailbox-owned `generic_no_match_if` / `generic_no_match_instruction` pair is now the strict last unmatched fallback after those rows.
- If that strict last unmatched fallback actually sends a reply, the runner should finalize the message by marking it seen immediately so the next unread poll does not handle it again.
- If the strict unmatched AI/final-fallback path is fully evaluated but still ends in a terminal reject/error/not-certain outcome, the runner should now also mark the message seen for manual follow-up so the same unread mail does not chew through AI forever.
- Quota/billing failures from AI-enabled or unmatched-mail AI flows should now be promoted into explicit runtime alerts, and optional operator alert mail should be rate-limited/cooldown-based rather than spamming once per row.
- Unmatched fallback rows must continue in `sort_order` even if one row is rejected or hits a row-local AI/API evaluation failure; only a real sent reply should stop that unmatched-row loop early.
- Generic unmatched AI request context should now expose which unmatched path triggered the request (`advanced_row_rule` vs `mailbox_final_fallback`) so upstream SocialGPT/OpenAI audit logs can be mapped back to the real standalone fallback source.
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
- Tools case sync is now part of the shared operator contract: if the runner records a message outcome or manual reply/handled action, it should best-effort push that thread state back into Tools without breaking mailbox handling when Tools sync itself fails.
- Remote case inspection should no longer stop at excerpts: when the standalone runner has raw headers, parsed header maps, raw plain text, normalized plain text, reply-aware text, or HTML body content, that data should be forwarded to Tools so the remote case page can expose the same message more faithfully.
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
sh -n cron-run.sh
php -l run
php -l public/index.php
php -l src/Tools/ToolsApiClient.php
php -l src/Mail/ImapMailboxClient.php
php -l src/Mail/MimeDecoder.php
php -l src/Support/MarkdownRenderer.php
php -l src/Support/MessageStateStore.php
php -l src/Runner/MailAssistantRunner.php
php -l src/Web/WebApp.php
php -l tests/manual-reply-regression.php
php -l tests/tools-case-sync-regression.php
php -l tests/unanswered-report-regression.php
php -l tests/markdown-html-reply-regression.php
php -l tests/cron-script-lock-regression.php
php -l tests/quota-alert-regression.php
php run --help
php run --self-test
```

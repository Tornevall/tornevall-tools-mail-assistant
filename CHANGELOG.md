# CHANGELOG

## 0.3.36 - 2026-04-21

- HTML-only inbound mail bodies are now converted into readable plain text before rule matching, unmatched-mail AI triage, appended **Summary of your request** excerpts, and saved local message copies are built, so the standalone client no longer falls back to acting like only the subject exists when the body arrived as HTML.
- MIME body decoding is now charset-aware (`UTF-8`, `Windows-1252`, `ISO-8859-*` fallback detection/conversion), which makes malformed or non-UTF8 support mail far more likely to produce usable body text instead of garbled context.
- The strict unmatched-mail AI parser now tolerates a few common sloppy JSON formatting mistakes from the model/provider (for example smart quotes or trailing commas) before it gives up as `no_matching_rule_generic_ai_invalid_json`.
- Added regression coverage in `tests/html-body-decoding-regression.php`, and extended `tests/generic-no-match-json-regression.php` so slightly repairable JSON still yields a safe deny decision instead of always looking malformed.

## 0.3.35 - 2026-04-21

- The standalone dashboard now behaves more like a real lightweight operator mail client: latest-run message cards can assign a local rule context, send a manual reply, or mark a message handled/read without waiting for another cron pass.
- Manual replies now reuse the same outgoing reply pipeline as automatic replies, including the styled HTML/plain-text multipart body and the appended **Summary of your request** excerpt when the selected rule allows it.
- Terminal unmatched-mail outcomes are no longer left unread forever: when the strict unmatched AI/final fallback path is fully evaluated but still rejects/fails (including quota/billing failures), the runner now marks that message seen for manual follow-up so unread polling does not keep retrying it endlessly.
- AI quota/billing failures are now promoted into explicit runtime alerts, shown in the standalone dashboard, and can optionally trigger cooldown-limited operator alert mail through `MAIL_ASSISTANT_QUOTA_ALERT_EMAIL`.
- Added regression coverage in `tests/manual-reply-regression.php` and `tests/quota-alert-regression.php`, and updated `tests/generic-no-match-rows-regression.php` for the new terminal mark-seen behavior.

## 0.3.34 - 2026-04-21

- An unchecked mailbox `generic_no_match_ai_enabled` checkbox is now treated even more defensively: the standalone runner refuses to materialize any unmatched AI rows at all when that Tools checkbox is off, even if older `generic_no_match_if`, `generic_no_match_instruction`, or `generic_no_match_rules[]` values are still populated.
- The Tools admin mailbox form now submits an explicit `0` value for that checkbox when it is unchecked, which makes disabling the unmatched AI fallback more reliable across normal and AJAX saves.
- Generic unmatched AI requests now include their source (`advanced_row_rule` vs `mailbox_final_fallback`) and no-match rule id in the request context, so SocialGPT/OpenAI audit logs make it clearer which unmatched fallback path triggered the AI call.
- `tests/skip-mark-seen-regression.php` now proves that populated unmatched fallback fields still stay inactive when the checkbox is off, `tests/generic-no-match-rows-regression.php` now also covers the final all-rows-reject unread path, and the older dedicated `tests/generic-no-match-runner-regression.php` regression is no longer needed.

## 0.3.33 - 2026-04-21

- The unmatched-mail AI fallback is now enabled only by the mailbox checkbox coming from Tools config; environment-only toggles no longer activate it by themselves.
- Mailbox-level `generic_no_match_if` / `generic_no_match_instruction` now act as the strict **last unmatched fallback** after any ordered `generic_no_match_rules[]` rows have already been tried.
- When that strict last fallback actually sends a reply, the standalone runner now finalizes the message by marking it seen immediately so it is not picked up again by unread polling.
- Added regression coverage in `tests/generic-no-match-final-fallback-regression.php` and tightened `tests/skip-mark-seen-regression.php` so the old env toggle cannot silently re-enable the fallback.

## 0.3.32 - 2026-04-20

- The standalone dashboard's **Refresh dashboard** button now calls the supported dashboard reload path again instead of failing with `Unknown ajax action.`.
- The activity tab now shows configured mailbox cards even before a dry-run/real run has saved any per-message activity, and it explicitly explains that this view is a latest-run operator inbox rather than a live IMAP mail client.
- The Tools config tab now exposes the readable matched-rule rows, fallback rule details, and unmatched AI/IF row settings (including footer, AI model, and reasoning effort) instead of forcing operators to dig only in raw JSON.
- Added regression coverage in `tests/dashboard-config-visibility-regression.php` for config-only mailbox visibility plus the richer dashboard config summary fields.

## 0.3.31 - 2026-04-20

- Clear-text contact-form style inbound mails that begin with structured lines such as `From:`, `Subject:`, `Sender IP:`, and `Message Body:` no longer get truncated to only the first header-like line during reply-aware parsing.
- The standalone request-summary pipeline now keeps the actual problem description (for example the real delisting/Cloudflare complaint text) plus useful context such as sender IP when those fields are present in the body itself.
- Outgoing appended **Summary of your request** blocks, local body excerpts, and AI request context now therefore preserve those clearer clear-text problem details instead of collapsing to only a sender line.
- Added regression coverage in `tests/contact-form-summary-regression.php` while keeping the existing malformed-wrapper and body-only summary regressions green.

## 0.3.30 - 2026-04-20

- Added explicit release discipline for the standalone assistant: every assistant change should now end in an immediate commit, an updated changelog entry, and its own incremental semantic tag.
- Added a parity rule for any future Tools-admin mail-client/operator surface so shared mail-client behavior must be updated in both UIs together.
- Retired the older `tests/rule-context-priority-regression.php` private-method regression because the newer overlap-selection coverage now exercises the same rule-priority outcome through richer runner-level diagnostics instead of a brittle internal score assertion.

## 0.3.29 - 2026-04-20

- The standalone dashboard now renders a more human-readable, mail-client-style operator view instead of only dumping raw JSON blocks for last-run, history, and config.
- Latest-run mailbox activity is now shown as expandable message cards with subject/from/to/date, body preview, selected-rule/no-match diagnostics, thread metadata, and optional saved local header maps when a cached message copy exists.
- The dashboard now also summarizes Tools mailbox/rule configuration more readably while still keeping raw JSON available in collapsible advanced sections.
- Per-message run diagnostics now include date/body excerpt, which helps the dashboard behave more like an inbox preview surface without needing live IMAP administration yet.
- Dashboard AJAX toolbar actions now reuse the readable operator panels cleanly without throwing on a removed raw last-run element reference after refresh/self-test/dry-run responses.

## 0.3.28 - 2026-04-20

- Reply continuity is now more tolerant of older/malformed follow-ups: if `In-Reply-To` / `References` are missing or unusable, the standalone runner can fall back to normalized subject + same participants (`from` / `to`) before it gives up as no-match.
- Standalone replies now generate and persist an explicit outgoing `reply_message_id`, so later follow-ups that reference the assistant's own sent mail can be linked back to the earlier handled conversation more reliably.
- Local thread-history matching now normalizes Message-Id-style values more strictly (including angle-bracket stripping), which makes stored continuity hints behave more like real mail headers.
- Explicitly linked follow-ups in a previously approved unmatched thread can now skip the first allow-condition triage and continue directly on that same previously used unmatched row instead of re-asking the classifier from scratch.
- Per-message run diagnostics now also expose `thread_key`, `in_reply_to`, and `references[]` for easier reply-chain debugging.
- Added regression coverage in `tests/reply-chain-subject-fallback-regression.php`, `tests/reply-chain-reply-message-id-regression.php`, and `tests/reply-chain-generic-no-match-bypass-regression.php`.

## 0.3.27 - 2026-04-20

- Reply-chain follow-ups can now reuse the previously handled matched rule when `In-Reply-To` / `References` link the new unread message to an earlier handled conversation in local message-state.
- Reply-chain follow-ups that were previously handled through unmatched-mail AI can now also prioritize the earlier `generic_no_match_rules[]` row first, instead of always restarting from the first unmatched row and potentially rejecting a shorter follow-up.
- Local thread summaries sent to AI now also include prior selected-rule / matched-no-match-rule metadata, which gives the model clearer continuity context for follow-up questions.
- Added regression coverage in `tests/reply-chain-rule-reuse-regression.php` and `tests/reply-chain-generic-no-match-reuse-regression.php`.

## 0.3.26 - 2026-04-20

- Expanded the standalone `README.md` with a clearer requirements section covering the real Tools-side prerequisites: Tools account, `/admin/mail-support-assistant` access, active personal client token, mailbox config, OpenAI approval when AI is used, and optional relay token/permission.
- Added a practical pre-flight checklist for first real runs, plus explicit note that a working outbound mail transport must exist.
- Added direct GitHub ticket guidance for bugs, feature requests, and setup/support questions: <https://github.com/Tornevall/tornevall-tools-mail-assistant>.

## 0.3.25 - 2026-04-19

- Generic unmatched fallback rows now keep falling through to later active rows even when an earlier row hits a row-local AI/API evaluation error, instead of aborting the whole unmatched-mail pass immediately.
- No-match diagnostics now also record `evaluated_no_match_rules[]` so operators can see exactly which unmatched rows were tried, in order, before a reply was sent or the message stayed unread.
- Added/expanded regression coverage in `tests/generic-no-match-rows-regression.php` and `tests/generic-no-match-runner-regression.php` for both clean reject fallthrough and row-local failure fallthrough.

## 0.3.24 - 2026-04-19

- Outgoing assistant replies are now stamped with `X-Tornevall-Mail-Assistant: sent`.
- Incoming unread messages carrying that marker are now skipped as `assistant_sent_marker` before rule matching/reply, and marked seen to prevent self-reply loops.
- Added regression coverage in `tests/assistant-sent-marker-regression.php`.

## 0.3.23 - 2026-04-19

- Trailing AI-generated signoffs are now stripped repeatedly before static footers are appended, which avoids duplicated closings such as both `Best regards` and `Regards` in the same outgoing reply.
- Generic unmatched fallback replies now run the same trailing-signoff cleanup before applying a row/mailbox footer override.
- Added regression coverage in `tests/footer-signoff-dedup-regression.php`.

## 0.3.22 - 2026-04-19

- Standalone runner now reads SpamAssassin `X-Spam-Score` as an additional score source when `X-Spam-Status` score metadata is missing.
- Mailbox defaults can now carry `spam_score_reply_threshold`; when an unread message score is above that threshold, the runner suppresses reply handling and leaves the message unread.
- Reply-suppressed messages are now tracked with reason `spam_score_reply_threshold_exceeded` and counters `messages_reply_spam_score_suppressed` / `mailboxes[].reply_spam_score_suppressed`.
- Added regression coverage in `tests/spam-score-threshold-regression.php` for threshold-based reply suppression + unread preservation.

## 0.3.21 - 2026-04-19

- Local conversation/thread summaries are now built from prior-handled message state and injected into AI request context for matched-rule replies, giving the model awareness of earlier turns in the same thread.
- `MessageStateStore::summarizeThread()` collects the most recent local state records that share the same thread root (via `in_reply_to` / `references` matching) and passes them as `thread_context.messages` to `generateAiReply`.
- Added regression test `tests/thread-context-regression.php` to verify that AI receives local prior-reply context when the incoming message is part of an already-handled thread.

## 0.3.20 - 2026-04-19

- Local `message-state.json` no longer influences unread processing at all; unread IMAP mail is always re-evaluated from IMAP state instead of being skipped because of prior local history.
- History diagnostics are now opt-in: `message_state` and per-mailbox `message_state_records[]` are hidden from normal run output unless `php run --include-history` is used.
- The CLI help now documents `--include-history` as the explicit switch for persisting/showing local message-history diagnostics.

## 0.3.19 - 2026-04-19

- Unmatched-mail fallback now supports ordered add-row rules from Tools config (`defaults.generic_no_match_rules[]`) instead of relying on only one generic IF/instruction pair.
- The standalone runner now evaluates active unmatched rows in `sort_order` order and can fall through to later rows when earlier rows are rejected by strict AI triage.
- Per-row footer/model/reasoning overrides are now honored on unmatched fallback replies when provided.
- Backward compatibility remains for legacy single fields (`generic_no_match_if`, `generic_no_match_instruction`, `generic_no_match_footer`) when row data is missing.

## 0.3.18 - 2026-04-19

- Matched-rule AI requests now harden language obedience: explicit rule instructions such as "reply in English" are promoted into an actual request-language override instead of relying only on free-form prompt interpretation.
- Matched-rule AI prompts are now stricter about operator intent overall: authoritative custom instructions are treated as the highest-priority constraint, and the model is explicitly told not to add its own closing/signature when the standalone footer will be appended separately.
- Rule-collision winner selection now treats lower `sort_order` as the explicit operator priority, and when priorities are tied it prefers more contextual matches (`subject` / `body`) over broad sender-only matches.
- Messages that match a rule but do not actually send a reply (`reply.enabled=false`) now stay unread by default instead of being silently marked seen/moved/deleted as though an outgoing reply had been delivered.
- Reply recipient parsing is now more tolerant of semicolon-separated and line-wrapped CC/BCC address lists, which improves copied-recipient preservation in both SMTP envelope handling and Tools relay payloads.
- Returned AI text is now locally validated against critical operator instructions before send: obvious violations such as replying in Swedish despite an English-only rule, omitting required redirect addresses, missing required “must state” redirect facts, or claiming responsibility/handling despite a redirect-only instruction now abort the reply instead of sending a contradictory mail.
- Rules that explicitly say “write only the email body” now suppress the appended original-request summary block so the outgoing message body stays body-only.
- Added regression coverage for explicit English AI enforcement, tied-priority contextual rule selection, explicit unread preservation when no reply is sent, stricter redirect-instruction compliance, and body-only summary suppression.

## 0.3.17 - 2026-04-18

- Standalone replies can now fall back to `MAIL_ASSISTANT_DEFAULT_BCC` from `.env` when neither the matched rule nor the mailbox defaults define any BCC recipient.
- Reply transports now normalize reply recipients more strictly before SMTP/Tools relay delivery, which hardens BCC forwarding and avoids losing BCC recipients when addresses include display names or combined header formatting.
- Mailbox-level unmatched-mail AI now uses two separate admin-managed fields from Tools: `generic_no_match_if` for the allow-condition and `generic_no_match_instruction` for the actual reply instructions.
- The standalone no-match AI path no longer trusts any free-form model reply. It now requires strict JSON from AI and only sends a reply when the decision is explicitly `can_reply=true` with `certainty="high"` and a non-empty `reply` payload.
- Rejected or non-high-certainty no-match AI decisions now also have their internal reply payload blanked, so diagnostics cannot accidentally look like a usable answer was approved.
- If the new mailbox-level `generic_no_match_if` field is empty, unmatched-mail AI is treated as unconfigured and no fallback reply is sent.
- SpamAssassin wrapper prose is still ignored as outer noise during that decision, while SpamAssassin score/tests remain available to the AI as risk signals.
- Local run/message diagnostics now preserve the no-match AI decision metadata so operators can see why a regelless message was rejected or accepted.
- Regression coverage now explicitly exercises the strict unmatched-mail JSON contract and verifies that rejected no-match AI decisions stay unread instead of silently mutating IMAP state.
- Regression coverage now also includes BCC-routing verification for Tools relay delivery, including normalized `to` / `cc` / `bcc` recipient lists.
- Regression coverage now also verifies that the new env-level default BCC is applied when mailbox/rule BCC values are empty.

## 0.3.16 - 2026-04-18

- CLI/manual runs now mirror live log lines to stdout by default (`MAIL_ASSISTANT_CLI_PROGRESS=true`), so `bash cron-run.sh` no longer stays silent until the final JSON summary is printed.
- AI reply generation now also falls back to the configured fallback model when the primary model returns an empty response body, not only when the HTTP/API request itself throws an error.
- The runner now records per-message `message_results[]` diagnostics in run summaries, making it easier to see whether each unread message was handled, skipped, state-skipped, warned, or failed.
- Unread messages that already have a prior local state proving that a reply was sent are now skipped as `previous_reply_recorded_unread` instead of being auto-replied again, which prevents duplicate replies when IMAP read-state lags or was manually reset.
- Reply flows now detect and report IMAP finalize problems (mark-seen / move / delete) explicitly through reasons such as `rule_matched_replied_imap_finalize_failed` instead of silently looking fully handled.
- Outgoing HTML reply styling now uses stronger explicit text colors plus light-only color-scheme hints so quoted/manual replies are less likely to render white text on a white background in mail clients.

## 0.3.15 - 2026-04-18

- Mail Support Assistant AI requests now explicitly default to the same reply language as the incoming mail (`response_language=auto`) instead of relying only on loose prompt inference.
- The standalone AI fallback model now defaults to `o4`, and that fallback retry intentionally omits `reasoning_effort` metadata because `o4` should be treated as a non-reasoning fallback path here.
- Malformed wrapper-style mails are now cleaned more aggressively before rule matching, AI context building, and reply excerpts: SpamAssassin wrapper text, forwarded `.eml` header dumps, and embedded header runs are stripped so the real original request survives.
- Outgoing replies now also include a compact excerpt of the original request, making it easier to see what the sender actually wrote even when the inbound message body was messy.
- AI failure messages now include the model trail that was attempted, so mailbox summaries show which model(s) produced an empty response or failed before a reply was aborted.

## 0.3.14 - 2026-04-18

- Rule resolution now evaluates all matching rules before selecting a winner, so broad rules (for example generic Gmail sender matches) no longer silently override more specific rules such as copyright-notice flows.
- Winner selection now prefers the most specific rule first (most active match fields, then longer combined match text, then `sort_order`), and the selected rule plus all competing matches are recorded in standalone diagnostics.
- AI requests from matched rules now retry rate-limited `429 / Too Many Attempts` failures before the standalone runner gives up and abandons the AI path.
- AI-enabled rules without an explicit `template_text` fallback no longer send the misleading hardcoded sentence `Thank you for your message. We have reviewed it.` when AI fails; the reply is now aborted and logged instead.
- Per-message failures now stay isolated inside the mailbox run, so one failed AI-generated reply does not abort all later messages in the same mailbox pass.

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


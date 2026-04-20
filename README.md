# Tornevall Tools Mail Assistant

Standalone PHP client for the Tools **Mail Support Assistant** workflow.

This project is intentionally small and can stay **databaseless**:

- mailbox + rule configuration lives in Tools admin
- the client fetches config from `GET /api/mail-support-assistant/config`
- unmatched fallback now supports ordered add-row IF + instruction rules (`defaults.generic_no_match_rules[]`)
- local state is limited to session data, logs, the last run summary, and an optional local message-history file in `storage/`

The project is **not** a Laravel app and must stay runnable as plain PHP.

## What is included

- `run` - CLI entrypoint for cron/manual runs
- `cron-run.sh` - tiny shell wrapper for cron jobs
- `public/index.php` - mini web UI with env-based login
- `src/` - Tools API client, IMAP adapter, MIME decoding, runner, and local auth
- `templates/` - minimal login/dashboard templates
- `storage/` - logs, last-run summary, and persisted local state
- `storage/state/message-state.json` - optional normalized local message history per mailbox for diagnostics when explicitly requested

## Requirements

- PHP 7.4+
- `ext-curl`
- `ext-json`
- `ext-session`
- `ext-imap` recommended for real mailbox handling

### Tools-side prerequisites

This standalone client depends on a real Tools setup. Before it can do useful work, you need:

- a real **Tools / ToolsAPI account** on the Tools host you want to use
- access to `/admin/mail-support-assistant`
- at least one configured mailbox in Tools admin
- a personal active `provider_mail_support_assistant` token
- that token marked AI-capable (`is_ai=1`)
- approved `provider_openai` access for the token owner if any mailbox/rule uses AI and the owner is not admin

Optional, depending on delivery mode:

- a personal active `provider_mail_support_assistant_mailer` token if you want to send replies through Tools relay
- permission `mail-support-assistant.relay` for that relay-token owner when relay should be available to non-admin users

### Local runtime prerequisites

- writable `storage/` for logs, summaries, and optional local state/history
- network reachability to the Tools base URL configured in `.env`
- working IMAP mailbox credentials stored in Tools admin
- one outbound mail strategy that actually exists in your environment:
  - direct SMTP
  - local PHP `mail()`
  - a custom MTA command
  - or the Tools relay endpoint

Without `ext-imap`, the project still boots and the UI works, but real mailbox polling will fail with a clear runtime message.

## Setup

1. Copy `.env.example` to `.env`
2. Set:
   - `MAIL_ASSISTANT_WEB_USER`
   - `MAIL_ASSISTANT_WEB_PASSWORD`
   - `MAIL_ASSISTANT_TOOLS_BASE_URL`
   - `MAIL_ASSISTANT_TOOLS_TOKEN`
   - optional dedicated relay token: `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`
   - optional: `MAIL_ASSISTANT_SPAMASSASSIN_SKIP_SCORE` and `MAIL_ASSISTANT_SPAMASSASSIN_COPY_SCORE`
   - optional AI tuning: `MAIL_ASSISTANT_AI_MODEL`, `MAIL_ASSISTANT_AI_FALLBACK_MODEL`, `MAIL_ASSISTANT_AI_REASONING_EFFORT`
   - optional CLI progress mirror: `MAIL_ASSISTANT_CLI_PROGRESS=true|false` (defaults to enabled for CLI so cron/manual runs print live log lines as they work)
   - optional mail transport tuning:
     - `MAIL_ASSISTANT_MAIL_TRANSPORT` (`smtp` | `pickup` | `php_mail` | `custom_mta` | `tools_api`)
     - `MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS` (optional comma-separated fallback order such as `smtp,tools_api,pickup`)
     - SMTP keys: `MAIL_ASSISTANT_SMTP_HOST`, `MAIL_ASSISTANT_SMTP_PORT`, `MAIL_ASSISTANT_SMTP_USERNAME`, `MAIL_ASSISTANT_SMTP_PASSWORD`
      - optional SMTP overrides: `MAIL_ASSISTANT_SMTP_SECURITY`, `MAIL_ASSISTANT_SMTP_EHLO`, `MAIL_ASSISTANT_SMTP_TIMEOUT`, `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE`
      - optional standalone reply fallback BCC: `MAIL_ASSISTANT_DEFAULT_BCC` (used only when neither the matched rule nor the mailbox config already supplies a BCC)
     - `MAIL_ASSISTANT_MTA_COMMAND` (used when transport is `custom_mta`)
     - `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API` (`true|false`)
   - optional no-match fallback gate: `MAIL_ASSISTANT_GENERIC_NO_MATCH_AI=1` (kept off by default unless enabled in Tools config or env)
3. In Tools admin, open `/admin/mail-support-assistant`
4. Create mailbox/rule config there first (mailboxes, rules, unmatched fallback rows, sender defaults)
5. Generate or rotate a personal `provider_mail_support_assistant` token there
6. Paste that token into this project's `.env`
7. If you plan to use Tools relay for outgoing mail, also generate/rotate `provider_mail_support_assistant_mailer` and set `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN`
8. Verify that your chosen outbound transport really exists locally or in Tools before the first real run

### Minimum checklist before first real run

- [ ] Tools account exists and can access `/admin/mail-support-assistant`
- [ ] At least one mailbox exists in Tools admin
- [ ] `MAIL_ASSISTANT_TOOLS_BASE_URL` points to the correct Tools host
- [ ] `MAIL_ASSISTANT_TOOLS_TOKEN` is valid and active
- [ ] IMAP credentials are correct in Tools admin
- [ ] The chosen outbound transport is configured
- [ ] `storage/` is writable
- [ ] If AI is enabled, the token owner has approved OpenAI access in Tools

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
php run --dry-run --include-history
```

### What `--dry-run` does

- fetches config from Tools
- resolves matching rules
- builds reply payloads
- skips actual reply send / IMAP move / IMAP delete actions

### What `--include-history` does

- includes `message_state` and per-mailbox `message_state_records[]` in the run summary
- persists the current pass into `storage/state/message-state.json`
- exposes prior local history matches as diagnostics
- makes it easier to inspect why a reply-chain follow-up reused an earlier matched rule or earlier unmatched fallback row
- does **not** block unread IMAP mail from being processed

## Mini web UI

Point your web server to `public/` and log in with the env credentials.

Current UI features:

- env-driven login/logout
- AJAX refresh of config/log/last-run panels without reloading the page
- AJAX self-test action
- AJAX-triggered safe dry-run action (reuses the same PHP runner as CLI)
- mail-client-style activity cards for the latest run instead of only raw JSON blocks
- expandable per-message diagnostics showing selected rule/no-match decision, thread metadata, and optional saved local headers
- human-readable Tools config summary (mailboxes, rule counts, unmatched fallback rows) with raw JSON still available under collapsible advanced sections
- optional local message-history summary from `storage/state/message-state.json` when history mode has been requested previously
- recent local saved message copies are now reused as body/header preview sources when available, so header inspection becomes possible without turning the dashboard into a full IMAP admin surface
- direct link back to Tools admin
- recent local log tail

### Web UI vs cron execution

- **Cron/manual execution should still use PHP CLI**: `php run ...`
- The web UI calls the same runner class for manual checks and dry-runs, but it is intended as an operator surface, not as the primary cron transport.
- The dashboard is now intentionally a lightweight operator inbox, not a full standalone admin clone of Tools. Mailbox/rule administration should still happen primarily in Tools, while the local UI focuses on inspection, diagnostics, and future lightweight manual handling.

## Cron example

```bash
cd /path/to/mail-support-assistant
php run --limit=10 >> storage/logs/cron.log 2>&1
```

## Support / changes / tickets

Use GitHub tickets for bugs, feature requests, setup clarifications, and standalone runtime issues:

- <https://github.com/Tornevall/tornevall-tools-mail-assistant>

## Release / parity discipline

- Every change to the standalone Mail Support Assistant should end in an immediate git commit in this repository.
- Every incremental standalone version should also get its own pushed semantic tag (`0.x.y`) so the changelog, repository history, and deployed/operator-visible behavior stay aligned.
- When shared mail-client/operator behavior changes (for example inbox-card UX, thread continuity diagnostics, manual handling flows, or reply-transport behavior), keep the standalone dashboard and any future Tools-admin mail-client surface synchronized in the same change instead of letting one UI drift.

## AI behavior

Rules can decide per message whether AI is enabled.

- If several rules match the same email, the standalone client now evaluates **all matching rules** before choosing one winner.
- Winner selection is deterministic: lower `sort_order` wins first (explicit operator priority), and when priorities are tied the runner prefers more contextual matches (`subject` / `body` before generic sender matches), then more active match fields, then longer combined match text.
- The selected rule and all competing matches are now recorded in local run/message-state diagnostics so collisions such as a broad Gmail rule vs a more specific copyright rule are visible afterwards.

- If a rule has `ai_enabled=false`, the client uses the static template text only.
- If a rule has `ai_enabled=true`, the client calls Tools' `POST /api/ai/socialgpt/respond` with the same personal token and forwards that rule's responder/persona/custom instruction/model/reasoning as explicit one-request overrides.
- For AI-enabled matched rules, the static template is now only a fallback if the AI call fails or returns an empty/non-usable response.
- AI requests now default to a primary model (`gpt-5.4`) and retry once with a fallback model (`o4`) if the primary call fails **or** if the primary request returns an empty reply body.
- Rate-limited AI failures (`429` / Too Many Attempts) are now retried automatically by the standalone client before it gives up on the AI path.
- Reasoning effort is still configurable (`MAIL_ASSISTANT_AI_REASONING_EFFORT`, default `medium`), but the standalone fallback path now intentionally omits reasoning metadata when it retries through `o4`.
- AI requests now default to the incoming sender language (`response_language=auto`), but explicit rule instructions such as **"reply in English"** are now promoted into a hard request-language override instead of being left as loose prose.
- AI prompts are now stricter overall: authoritative rule instructions are treated as highest priority, and the model is explicitly told not to joke, improvise company facts, or invent a separate sign-off/signature when the runner will append the footer itself.
- The standalone runner now also performs a local compliance check on returned AI text for critical instructions: if the generated reply violates an explicit English-only requirement, omits required redirect addresses, skips required “must state” facts, or claims responsibility/handling where the instruction says the notice should only be redirected, the reply is rejected instead of being sent.
- When static footer mode is used, trailing AI-generated signoff blocks are now stripped repeatedly before footer append so replies do not end up with duplicated closings such as both `Best regards` and `Regards`.
- Message bodies are now sanitized more aggressively before they are sent as AI request summary context: HTML/MIME noise, SpamAssassin wrapper text, forwarded `.eml` header dumps, and malformed embedded header blocks are stripped first so the actual original request survives.
- Reply-aware message parsing now strips common quoted history blocks before rule matching and AI summary generation, so follow-up emails in an existing thread can still match the intended support rule.
- Local message-state is now also used as a thread continuity hint: when `In-Reply-To` / `References` link a follow-up to a previously handled conversation, the runner can reuse the earlier matched rule even if the newest mail itself no longer matches the original subject/body criteria.
- The standalone runner now also generates and stores an explicit outgoing `Message-ID` for replies it sends itself, which makes later Gmail/Outlook follow-ups far more likely to link back to the locally tracked conversation instead of only referencing the assistant's last sent mail.
- If an older or malformed follow-up mail arrives without usable `In-Reply-To` / `References`, the runner can now still recover continuity through normalized subject + same participants (`from` / `to`) before it gives up as no-match.
- If an already-approved unmatched thread comes back through explicit reply headers, the standalone client can now continue that same unmatched row directly instead of re-running the first allow-condition triage from scratch for the same conversation.
- The token owner still needs approved `provider_openai` access in Tools unless that user is admin.
- Outgoing replies are now sent as `multipart/alternative`: a plain-text part is kept for compatibility, while the visible mail is also rendered as a small styled HTML card for more polished support replies.
- Outgoing assistant replies are now stamped with `X-Tornevall-Mail-Assistant: sent` so follow-up polling can identify assistant-originated mail and avoid self-reply loops.
- Outgoing replies now also append a compact excerpt of the original request, so the sent answer itself still shows what the user actually wrote even when the incoming mail was a malformed forwarded wrapper.
- Exception: if the rule instruction explicitly says to **write only the email body**, the standalone runner now suppresses that appended request-summary block so the final sent body stays closer to the operator's exact instruction.
- AI-enabled rules no longer fall back to the hardcoded generic sentence `Thank you for your message. We have reviewed it.` unless you explicitly configured a `template_text` fallback for that rule. If AI fails and no explicit template exists, the reply is aborted and the error is logged instead of sending a misleading canned answer.
- Mailbox run errors for failed AI replies now also include which model(s) were tried, making empty-response or fallback-path failures easier to diagnose.
- Mailbox defaults can now include `spam_score_reply_threshold` from Tools admin; if a message's SpamAssassin score is above that threshold, the runner suppresses reply handling for that message and explicitly keeps it unread.

### Generic AI fallback when no rule matches

- If a mailbox message has no matching rule, the runner can optionally try one generic AI reply path instead of always ignoring.
- This fallback is gated by config and is disabled unless explicitly enabled through one of these flags:
  - mailbox defaults: `generic_no_match_ai_enabled` (preferred)
  - top-level/settings/features variants from Tools config (`generic_no_match_ai_enabled` / `generic_reply_on_no_match`)
  - env fallback: `MAIL_ASSISTANT_GENERIC_NO_MATCH_AI=1`
- Mailbox config for that fallback now supports ordered add-row rules under `generic_no_match_rules[]`.
- Each active row can define:
  - `if`: describes which otherwise unmatched mail may be answered at all
  - `instruction`: describes how to write the reply if that IF condition clearly matches
  - optional `footer`, `ai_model`, and `ai_reasoning_effort`
- The no-match AI path now asks Tools/OpenAI for a **strict JSON decision** instead of trusting any free-form reply text.
- A generic fallback reply is sent only when the AI returns valid JSON with `can_reply=true`, `certainty="high"`, and a non-empty `reply` payload.
- If there are no valid active unmatched rows (non-empty `if` + `instruction`), the fallback is treated as unconfigured and no unmatched-mail AI reply is sent.
- Rows are evaluated in `sort_order` order and may fall through to later rows when an earlier row is rejected.
- That same fall-through now also applies when one unmatched row hits a row-local AI/API evaluation error; the runner logs the failed row and still tries later active rows before giving up.
- If a reply-chain follow-up is linked to an earlier handled unmatched conversation, the runner now prioritizes the previously used unmatched row first before checking the rest of the active rows, which helps repository/API follow-ups stay on the same support path.
- For explicitly linked follow-ups in an already-approved unmatched thread, the runner can now skip the first allow-condition re-check entirely and go straight to generating the continuation reply on that same previously used unmatched row.
- The AI is told to ignore outer SpamAssassin wrapper prose when it only forwards the original email, while still using SpamAssassin score/tests as safety hints.
- Mailbox-level unmatched-mail fallback can now also carry its own `generic_no_match_ai_reasoning_effort` override from Tools config; Tools still decides per selected model whether reasoning is actually forwarded.
- The config payload from Tools can now also include additive `user.ai_daily_budget` metadata so operators can inspect the effective AI token cap/remaining budget that Mail Support Assistant shares with the SocialGPT reply endpoint.
- If the fallback path is disabled, unconfigured, rejected as unsafe, invalid, empty, fails, or otherwise does not return a high-confidence allow decision, the message remains ignored.

## Notes

- Unmatched mail is left untouched.
- If a message is skipped because no rule matches or the generic no-match fallback is disabled, unanswerable, or fails, it now stays unread even when `mark_seen_on_skip` is enabled.
- Incoming unread mail containing `X-Tornevall-Mail-Assistant: sent` is now skipped before rule matching/reply as an anti-loop guard.
- Those assistant-marked loop candidates are marked seen after skip to avoid repeated unread reprocessing.
- If a rule matches but `reply.enabled=false`, the message now also stays unread by default instead of being silently marked seen/moved/deleted as if a reply had actually been sent.
- `mark_seen_on_skip` now only applies to deliberate heuristic skips such as high-score SpamAssassin junk, not to configuration-driven no-match cases.
- Cron/manual execution only polls unread mail. Already-read mail is skipped immediately.
- Unread mail may be reprocessed on later runs even if the same `Message-Id` already exists in local history; the local state file is diagnostic only and never blocks unread IMAP mail.
- That same local state can now still assist linked follow-up replies by reusing the earlier matched rule or prioritizing the earlier unmatched row when `In-Reply-To` / `References` clearly point to the same conversation.
- When explicit reply headers are missing or damaged, the same local state can now also fall back to normalized subject + same participants so older support threads still have a chance to continue on the earlier rule path.
- Matchers currently support `from`, `to`, `subject`, and optional body text contains checks.
- Subject matching is now reply-aware (`Re:`, `Fwd:`, `Sv:` prefixes are stripped before rule checks), and outgoing replies now preserve `In-Reply-To` / `References` headers so answers stay in the same thread.
- Unmatched mail is now also logged more explicitly with mailbox/from/to/subject details, which makes `scanned` + `skipped` runs easier to diagnose.
- No-match handling now records clearer state reasons such as `no_matching_rule_generic_ai_disabled`, `no_matching_rule_generic_ai_unconfigured`, `no_matching_rule_generic_ai_rejected`, `no_matching_rule_generic_ai_invalid_json`, `no_matching_rule_generic_ai_not_certain`, `no_matching_rule_generic_ai_empty_reply`, `no_matching_rule_generic_ai_error`, and `no_matching_rule_generic_ai_replied`.
- No-match diagnostics now also include `generic_ai_decision.evaluated_no_match_rules[]`, so operators can see which unmatched fallback rows were actually tried, in order, before a reply was sent or the message was left unread.
- Run summaries now separate `messages_read_skipped` from other skipped categories, so mail that is already marked read at ingest is tracked clearly and does not need to be interpreted as `no_matching_rule` noise.
- Run summaries now also expose per-mailbox `message_results[]` entries so operators can see what happened to each scanned message during the current pass (`handled`, `skipped`, `warning`, `error`).
- Those per-message diagnostics now also expose `thread_key`, `in_reply_to`, and `references[]`, which makes reply-chain troubleshooting much easier when a message unexpectedly falls through to no-match.
- History-specific fields such as `message_state` and `message_state_records[]` are hidden by default and only included when `--include-history` is used.
- When a reply is sent successfully but the IMAP finalize step (`markSeen`, move, or delete) fails, the run now records that as an explicit warning reason such as `rule_matched_replied_imap_finalize_failed` instead of silently looking fully handled.
- The runner now parses SpamAssassin headers so heavily flagged messages can be skipped before handling, while wrapper-style SpamAssassin rewrites can still be copied locally and stripped from the body before rule matching/AI.
- Spam score extraction now also reads `X-Spam-Score` directly when `X-Spam-Status` does not provide a parseable `score=` value.
- Local SpamAssassin/debug copies are written under `storage/cache/message-copies/` when the runner detects a rewritten wrapper or another message worth preserving for review.
- The mini dashboard can still show local message-history details when history mode has been requested, but unread reruns are never blocked by that history.
- Reply sending now supports multiple transports:
  - `smtp` (default, direct SMTP delivery without requiring local sendmail/postfix)
  - `php_mail` (legacy/local PHP `mail()`)
  - `custom_mta` (pipes RFC822 message to `MAIL_ASSISTANT_MTA_COMMAND`)
  - `tools_api` (relays via `POST /api/mail-support-assistant/send-reply`)
- All of those reply transports now emit both plain text and styled HTML when a reply is sent, so mailbox clients that prefer HTML get a formatted message while older clients still see the plain-text fallback.
- Generic unmatched fallback replies now apply the same trailing-signoff cleanup before row/mailbox footer override, which keeps one clean closing block there as well.
- The generated HTML reply now uses stronger explicit text colors plus light-only color-scheme hints so manual replies/quoted history in mail clients are less likely to end up as white text on a white background.
- If local transport fails and `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`, the runner automatically retries through the Tools relay endpoint.
- If `MAIL_ASSISTANT_MAIL_TRANSPORT=tools_api` but `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN` is missing, the runner now skips relay mode and continues with the configured fallback order instead of aborting the whole reply attempt.
- `MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS` can define an explicit ordered fallback chain. If it is left empty, the runner keeps the legacy compatibility behavior where `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true` appends the Tools relay as a fallback.
- SMTP is now more forgiving when optional override keys are left blank: empty `MAIL_ASSISTANT_SMTP_SECURITY`, `MAIL_ASSISTANT_SMTP_EHLO`, and `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE` automatically fall back to sensible defaults instead of being treated as invalid configuration.
- In practice, `MAIL_ASSISTANT_SMTP_HOST` plus the usual `MAIL_ASSISTANT_SMTP_PORT`, `MAIL_ASSISTANT_SMTP_USERNAME`, and `MAIL_ASSISTANT_SMTP_PASSWORD` are enough for most authenticated SMTP setups.
- If neither a matched rule nor the mailbox defaults define a BCC recipient, the standalone runtime can now fall back to `MAIL_ASSISTANT_DEFAULT_BCC` from `.env`.
- CC/BCC parsing is now more tolerant of semicolon-separated or line-wrapped address lists, which helps preserve copied recipients even when mailbox config or relay headers are formatted less strictly.
- Tools relay requires a dedicated personal token (`provider_mail_support_assistant_mailer`) and the `mail-support-assistant.relay` permission for the token owner (admin bypass still applies); the relay payload can now include both `body` and additive `body_html`.


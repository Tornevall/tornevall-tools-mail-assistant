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
- Message bodies are now sanitized more aggressively before they are sent as AI request summary context: HTML/MIME noise, SpamAssassin wrapper text, forwarded `.eml` header dumps, and malformed embedded header blocks are stripped first so the actual original request survives.
- Reply-aware message parsing now strips common quoted history blocks before rule matching and AI summary generation, so follow-up emails in an existing thread can still match the intended support rule.
- The token owner still needs approved `provider_openai` access in Tools unless that user is admin.
- Outgoing replies are now sent as `multipart/alternative`: a plain-text part is kept for compatibility, while the visible mail is also rendered as a small styled HTML card for more polished support replies.
- Outgoing replies now also append a compact excerpt of the original request, so the sent answer itself still shows what the user actually wrote even when the incoming mail was a malformed forwarded wrapper.
- Exception: if the rule instruction explicitly says to **write only the email body**, the standalone runner now suppresses that appended request-summary block so the final sent body stays closer to the operator's exact instruction.
- AI-enabled rules no longer fall back to the hardcoded generic sentence `Thank you for your message. We have reviewed it.` unless you explicitly configured a `template_text` fallback for that rule. If AI fails and no explicit template exists, the reply is aborted and the error is logged instead of sending a misleading canned answer.
- Mailbox run errors for failed AI replies now also include which model(s) were tried, making empty-response or fallback-path failures easier to diagnose.

### Generic AI fallback when no rule matches

- If a mailbox message has no matching rule, the runner can optionally try one generic AI reply path instead of always ignoring.
- This fallback is gated by config and is disabled unless explicitly enabled through one of these flags:
  - mailbox defaults: `generic_no_match_ai_enabled` (preferred)
  - top-level/settings/features variants from Tools config (`generic_no_match_ai_enabled` / `generic_reply_on_no_match`)
  - env fallback: `MAIL_ASSISTANT_GENERIC_NO_MATCH_AI=1`
- Mailbox config for that fallback now has two separate admin-managed fields:
  - `generic_no_match_if`: describes which otherwise unmatched mail may be answered at all
  - `generic_no_match_instruction`: describes how to write the reply if that IF condition clearly matches
- The no-match AI path now asks Tools/OpenAI for a **strict JSON decision** instead of trusting any free-form reply text.
- A generic fallback reply is sent only when the AI returns valid JSON with `can_reply=true`, `certainty="high"`, and a non-empty `reply` payload.
- If the `generic_no_match_if` field is empty, the fallback is treated as unconfigured and no unmatched-mail AI reply is sent.
- The AI is told to ignore outer SpamAssassin wrapper prose when it only forwards the original email, while still using SpamAssassin score/tests as safety hints.
- Mailbox-level unmatched-mail fallback can now also carry its own `generic_no_match_ai_reasoning_effort` override from Tools config; Tools still decides per selected model whether reasoning is actually forwarded.
- The config payload from Tools can now also include additive `user.ai_daily_budget` metadata so operators can inspect the effective AI token cap/remaining budget that Mail Support Assistant shares with the SocialGPT reply endpoint.
- If the fallback path is disabled, unconfigured, rejected as unsafe, invalid, empty, fails, or otherwise does not return a high-confidence allow decision, the message remains ignored.

## Notes

- Unmatched mail is left untouched.
- If a message is skipped because no rule matches or the generic no-match fallback is disabled, unanswerable, or fails, it now stays unread even when `mark_seen_on_skip` is enabled.
- If a rule matches but `reply.enabled=false`, the message now also stays unread by default instead of being silently marked seen/moved/deleted as if a reply had actually been sent.
- `mark_seen_on_skip` now only applies to deliberate heuristic skips such as high-score SpamAssassin junk, not to configuration-driven no-match cases.
- Cron/manual execution only polls unread mail. Already-read mail is skipped immediately.
- Unread mail may be reprocessed on later runs even if the same `Message-Id` already exists in local history; the local state file is now diagnostic history only and is no longer used as a dedupe gate.
- Exception: if the same unread message already has a prior local state showing that a reply was sent, the runner now skips automatic resend and reports that explicitly as `previous_reply_recorded_unread` to avoid duplicate replies.
- Matchers currently support `from`, `to`, `subject`, and optional body text contains checks.
- Subject matching is now reply-aware (`Re:`, `Fwd:`, `Sv:` prefixes are stripped before rule checks), and outgoing replies now preserve `In-Reply-To` / `References` headers so answers stay in the same thread.
- Unmatched mail is now also logged more explicitly with mailbox/from/to/subject details, which makes `scanned` + `skipped` runs easier to diagnose.
- No-match handling now records clearer state reasons such as `no_matching_rule_generic_ai_disabled`, `no_matching_rule_generic_ai_unconfigured`, `no_matching_rule_generic_ai_rejected`, `no_matching_rule_generic_ai_invalid_json`, `no_matching_rule_generic_ai_not_certain`, `no_matching_rule_generic_ai_empty_reply`, `no_matching_rule_generic_ai_error`, and `no_matching_rule_generic_ai_replied`.
- Run summaries now separate `messages_read_skipped` from other skipped categories, so mail that is already marked read at ingest is tracked clearly and does not need to be interpreted as `no_matching_rule` noise.
- Run summaries also expose `messages_previously_recorded_unread` so operators can see when an unread thread was present in local history but was deliberately re-evaluated anyway.
- Run summaries now also expose per-mailbox `message_results[]` entries so operators can see what happened to each scanned message during the current pass (`handled`, `skipped`, `state_skipped`, `warning`, `error`).
- When a reply is sent successfully but the IMAP finalize step (`markSeen`, move, or delete) fails, the run now records that as an explicit warning reason such as `rule_matched_replied_imap_finalize_failed` instead of silently looking fully handled.
- The runner now parses SpamAssassin headers so heavily flagged messages can be skipped before handling, while wrapper-style SpamAssassin rewrites can still be copied locally and stripped from the body before rule matching/AI.
- Local SpamAssassin/debug copies are written under `storage/cache/message-copies/` when the runner detects a rewritten wrapper or another message worth preserving for review.
- The mini dashboard now shows both the last run summary and the local message-history file so operators can see prior outcomes without that history blocking unread reruns.
- Reply sending now supports multiple transports:
  - `smtp` (default, direct SMTP delivery without requiring local sendmail/postfix)
  - `php_mail` (legacy/local PHP `mail()`)
  - `custom_mta` (pipes RFC822 message to `MAIL_ASSISTANT_MTA_COMMAND`)
  - `tools_api` (relays via `POST /api/mail-support-assistant/send-reply`)
- All of those reply transports now emit both plain text and styled HTML when a reply is sent, so mailbox clients that prefer HTML get a formatted message while older clients still see the plain-text fallback.
- The generated HTML reply now uses stronger explicit text colors plus light-only color-scheme hints so manual replies/quoted history in mail clients are less likely to end up as white text on a white background.
- If local transport fails and `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true`, the runner automatically retries through the Tools relay endpoint.
- If `MAIL_ASSISTANT_MAIL_TRANSPORT=tools_api` but `MAIL_ASSISTANT_TOOLS_MAIL_TOKEN` is missing, the runner now skips relay mode and continues with the configured fallback order instead of aborting the whole reply attempt.
- `MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS` can define an explicit ordered fallback chain. If it is left empty, the runner keeps the legacy compatibility behavior where `MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=true` appends the Tools relay as a fallback.
- SMTP is now more forgiving when optional override keys are left blank: empty `MAIL_ASSISTANT_SMTP_SECURITY`, `MAIL_ASSISTANT_SMTP_EHLO`, and `MAIL_ASSISTANT_SMTP_FROM_ENVELOPE` automatically fall back to sensible defaults instead of being treated as invalid configuration.
- In practice, `MAIL_ASSISTANT_SMTP_HOST` plus the usual `MAIL_ASSISTANT_SMTP_PORT`, `MAIL_ASSISTANT_SMTP_USERNAME`, and `MAIL_ASSISTANT_SMTP_PASSWORD` are enough for most authenticated SMTP setups.
- If neither a matched rule nor the mailbox defaults define a BCC recipient, the standalone runtime can now fall back to `MAIL_ASSISTANT_DEFAULT_BCC` from `.env`.
- CC/BCC parsing is now more tolerant of semicolon-separated or line-wrapped address lists, which helps preserve copied recipients even when mailbox config or relay headers are formatted less strictly.
- Tools relay requires a dedicated personal token (`provider_mail_support_assistant_mailer`) and the `mail-support-assistant.relay` permission for the token owner (admin bypass still applies); the relay payload can now include both `body` and additive `body_html`.


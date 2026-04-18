<?php

namespace MailSupportAssistant\Runner;

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Support\Env;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Support\ProjectPaths;
use MailSupportAssistant\Tools\ToolsApiClient;
use RuntimeException;
use Throwable;

class MailAssistantRunner
{
    private ToolsApiClient $tools;
    private Logger $logger;
    private MessageStateStore $messageState;

    public function __construct(ToolsApiClient $tools, Logger $logger, ?MessageStateStore $messageState = null)
    {
        $this->tools = $tools;
        $this->logger = $logger;
        $this->messageState = $messageState ?: new MessageStateStore();
    }

    public function selfTest(): array
    {
        return [
            'ok' => true,
            'timestamp' => date('c'),
            'tools_base_url' => $this->tools->getBaseUrl(),
            'tools_token_configured' => $this->tools->hasToken(),
            'ext_imap_available' => function_exists('imap_open'),
            'storage_writable' => is_dir(ProjectPaths::storage()) && is_writable(ProjectPaths::storage()),
            'message_state_file' => ProjectPaths::messageStateFile(),
        ];
    }

    public function messageStateSummary(): array
    {
        return $this->messageState->summary();
    }

    public function cleanup(array $options = []): array
    {
        $purgeLog = !isset($options['log']) || !empty($options['log']);
        $purgeLastRun = !isset($options['last_run']) || !empty($options['last_run']);
        $purgeState = !isset($options['state']) || !empty($options['state']);
        $purgeCopies = !empty($options['copies']);

        $result = [
            'ok' => true,
            'timestamp' => date('c'),
            'purged' => [],
        ];

        if ($purgeLog) {
            $this->logger->purgeLog();
            $result['purged'][] = 'log';
        }

        if ($purgeLastRun) {
            $this->logger->purgeLastRun();
            $result['purged'][] = 'last_run';
        }

        if ($purgeState) {
            $this->messageState->purge();
            $result['purged'][] = 'message_state';
        }

        if ($purgeCopies) {
            $copiesDir = ProjectPaths::storage() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'message-copies';
            if (is_dir($copiesDir)) {
                $count = 0;
                foreach (glob($copiesDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $count++;
                    }
                }
                $result['purged'][] = 'message_copies';
                $result['message_copies_deleted'] = $count;
            }
        }

        $this->logger->info('Storage cleanup performed.', ['purged' => $result['purged']]);

        return $result;
    }

    public function run(array $options = []): array
    {
        $dryRun = !empty($options['dry_run']);
        $limitOverride = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
        $mailboxFilter = isset($options['mailbox']) ? (int) $options['mailbox'] : null;

        $summary = [
            'ok' => true,
            'timestamp' => date('c'),
            'dry_run' => $dryRun,
            'mailboxes_total' => 0,
            'messages_scanned' => 0,
            'messages_handled' => 0,
            'messages_skipped' => 0,
            'messages_state_skipped' => 0,
            'messages_previously_recorded_unread' => 0,
            'messages_spamassassin_skipped' => 0,
            'messages_read_skipped' => 0,
            'spamassassin_copies_saved' => 0,
            'errors' => [],
            'mailboxes' => [],
            'message_state' => $this->messageState->summary(),
            'message_state_mode' => 'history_only',
        ];

        try {
            $config = $this->tools->fetchConfig();
        } catch (Throwable $e) {
            $summary['ok'] = false;
            $summary['errors'][] = $e->getMessage();
            $this->logger->error('Failed to fetch config from Tools.', ['error' => $e->getMessage()]);
            $this->logger->saveLastRun($summary);
            return $summary;
        }

        $mailboxes = is_array($config['mailboxes'] ?? null) ? $config['mailboxes'] : [];
        if ($mailboxFilter !== null) {
            $mailboxes = array_values(array_filter($mailboxes, static fn (array $mailbox): bool => (int) ($mailbox['id'] ?? 0) === $mailboxFilter));
        }
        $summary['mailboxes_total'] = count($mailboxes);

        foreach ($mailboxes as $mailbox) {
            $mailboxSummary = [
                'id' => (int) ($mailbox['id'] ?? 0),
                'name' => (string) ($mailbox['name'] ?? ''),
                'scanned' => 0,
                'handled' => 0,
                'skipped' => 0,
                'state_skipped' => 0,
                'previously_recorded_unread' => 0,
                'spamassassin_skipped' => 0,
                'read_skipped' => 0,
                'spamassassin_copies_saved' => 0,
                'message_state_records' => [],
                'errors' => [],
            ];

            try {
                $imap = new ImapMailboxClient((array) ($mailbox['imap'] ?? []));
                $messages = $imap->fetchUnseenMessages($limitOverride ?: (int) (($mailbox['defaults']['run_limit'] ?? 20) ?: 20));
                $mailboxSummary['scanned'] = count($messages);
                $summary['messages_scanned'] += count($messages);

                foreach ($messages as $message) {
                    if (!empty($message['is_seen'])) {
                        $mailboxSummary['skipped']++;
                        $mailboxSummary['read_skipped']++;
                        $summary['messages_skipped']++;
                        $summary['messages_read_skipped']++;
                        $this->logger->info('Message skipped because it was already marked read at ingest.', [
                            'mailbox' => $mailbox['name'] ?? null,
                            'uid' => $message['uid'] ?? null,
                            'message_id' => $message['message_id'] ?? null,
                        ]);
                        continue;
                    }

                    $messageKey = $this->resolveMessageKey($message);
                    $messageId = (string) ($message['message_id'] ?? '');
                    $priorState = null;
                    if ($messageKey !== '') {
                        $priorState = $this->messageState->getRecord((int) $mailboxSummary['id'], $messageKey);
                    }
                    if (is_array($priorState)) {
                        $mailboxSummary['previously_recorded_unread']++;
                        $summary['messages_previously_recorded_unread']++;
                        $this->logger->info('Unread message was seen in local history and will be re-evaluated.', [
                            'mailbox' => $mailbox['name'] ?? null,
                            'uid' => $message['uid'] ?? null,
                            'message_id' => $messageId,
                            'message_key' => $messageKey,
                            'previous_status' => $priorState['status'] ?? null,
                            'previous_reason' => $priorState['reason'] ?? null,
                            'previous_recorded_at' => $priorState['recorded_at'] ?? null,
                        ]);
                    }

                    $spamDecision = $this->evaluateSpamAssassin($message);
                    if (!empty($spamDecision['save_copy'])) {
                        $this->saveMessageCopy($mailbox, $message, (string) ($spamDecision['reason'] ?? 'spamassassin_copy'));
                        $mailboxSummary['spamassassin_copies_saved']++;
                        $summary['spamassassin_copies_saved']++;
                    }

                    if (!empty($spamDecision['skip'])) {
                        $mailboxSummary['skipped']++;
                        $mailboxSummary['spamassassin_skipped']++;
                        $summary['messages_skipped']++;
                        $summary['messages_spamassassin_skipped']++;
                        $this->recordMessageState($mailboxSummary, $message, 'ignored', (string) ($spamDecision['reason'] ?? 'spamassassin_skip'), $dryRun);
                        $this->logger->info('Message skipped due to SpamAssassin heuristic.', [
                            'mailbox' => $mailbox['name'] ?? null,
                            'uid' => $message['uid'] ?? null,
                            'reason' => $spamDecision['reason'] ?? null,
                            'score' => $message['spam_assassin']['score'] ?? null,
                        ]);
                        if (!empty($mailbox['defaults']['mark_seen_on_skip']) && !$dryRun) {
                            $imap->markSeen((int) $message['uid']);
                        }
                        continue;
                    }

                    $rule = $this->matchRule($message, (array) ($mailbox['rules'] ?? []));
                    if (!$rule) {
                        $genericNoMatch = $this->tryHandleGenericNoMatch($imap, $config, $mailbox, $message, $dryRun);
                        if (!empty($genericNoMatch['handled'])) {
                            $this->recordMessageState($mailboxSummary, $message, 'handled', (string) ($genericNoMatch['reason'] ?? 'no_matching_rule_generic_ai_replied'), $dryRun);
                            $mailboxSummary['handled']++;
                            $summary['messages_handled']++;
                            continue;
                        }

                        $mailboxSummary['skipped']++;
                        $summary['messages_skipped']++;
                        $this->recordMessageState($mailboxSummary, $message, 'ignored', (string) ($genericNoMatch['reason'] ?? 'no_matching_rule'), $dryRun);
                        $this->logger->info('Message skipped because no configured rule matched.', [
                            'mailbox' => $mailbox['name'] ?? null,
                            'uid' => $message['uid'] ?? null,
                            'message_id' => $message['message_id'] ?? null,
                            'subject' => $message['subject'] ?? null,
                            'subject_normalized' => $message['subject_normalized'] ?? null,
                            'from' => $message['from'] ?? null,
                            'to' => $message['to'] ?? null,
                            'generic_no_match_reason' => $genericNoMatch['reason'] ?? null,
                        ]);
                        if (!empty($mailbox['defaults']['mark_seen_on_skip']) && !$dryRun) {
                            $imap->markSeen((int) $message['uid']);
                        }
                        continue;
                    }

                    $this->handleMessage($imap, $mailbox, $rule, $message, $dryRun);
                    $this->recordMessageState($mailboxSummary, $message, 'handled', 'rule_matched_replied', $dryRun);
                    $mailboxSummary['handled']++;
                    $summary['messages_handled']++;
                }
            } catch (Throwable $e) {
                $mailboxSummary['errors'][] = $e->getMessage();
                $summary['errors'][] = sprintf('Mailbox %s: %s', (string) ($mailbox['name'] ?? 'unknown'), $e->getMessage());
                $summary['ok'] = false;
                $this->logger->error('Mailbox run failed.', ['mailbox' => $mailbox['name'] ?? null, 'error' => $e->getMessage()]);
            }

            $summary['mailboxes'][] = $mailboxSummary;
        }

        $summary['message_state'] = $this->messageState->summary();

        $this->logger->info('Mail assistant run completed.', [
            'dry_run' => $dryRun,
            'mailboxes_total' => $summary['mailboxes_total'],
            'messages_scanned' => $summary['messages_scanned'],
            'messages_handled' => $summary['messages_handled'],
            'messages_skipped' => $summary['messages_skipped'],
            'messages_state_skipped' => $summary['messages_state_skipped'],
            'messages_previously_recorded_unread' => $summary['messages_previously_recorded_unread'],
            'messages_spamassassin_skipped' => $summary['messages_spamassassin_skipped'],
            'messages_read_skipped' => $summary['messages_read_skipped'],
            'spamassassin_copies_saved' => $summary['spamassassin_copies_saved'],
            'errors' => count($summary['errors']),
        ]);
        $this->logger->saveLastRun($summary);

        return $summary;
    }

    private function matchRule(array $message, array $rules): ?array
    {
        $from = (string) ($message['from'] ?? '');
        $to = (string) ($message['to'] ?? '');
        $subject = (string) (($message['subject_normalized'] ?? null) ?: ($message['subject'] ?? ''));
        $body = (string) (($message['body_text_reply_aware'] ?? null) ?: ($message['body_text'] ?? ''));

        foreach ($rules as $rule) {
            if (!$this->containsMatch($from, (string) (($rule['match']['from_contains'] ?? null) ?: ''))) {
                continue;
            }
            if (!$this->containsMatch($to, (string) (($rule['match']['to_contains'] ?? null) ?: ''))) {
                continue;
            }
            if (!$this->containsMatch($subject, (string) (($rule['match']['subject_contains'] ?? null) ?: ''))) {
                continue;
            }
            if (!$this->containsMatch($body, (string) (($rule['match']['body_contains'] ?? null) ?: ''))) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    private function containsMatch(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return true;
        }

        return stripos($haystack, $needle) !== false;
    }

    private function tryHandleGenericNoMatch(ImapMailboxClient $imap, array $config, array $mailbox, array $message, bool $dryRun): array
    {
        if (!$this->isGenericNoMatchAiEnabled($config, $mailbox)) {
            return [
                'handled' => false,
                'reason' => 'no_matching_rule_generic_ai_disabled',
            ];
        }

        try {
            $defaults = (array) ($mailbox['defaults'] ?? []);
            $aiResult = $this->tools->generateGenericAiReply($mailbox, $message, [
                'custom_instruction' => (string) (($defaults['generic_no_match_instruction'] ?? null) ?: ''),
                'ai_model' => (string) (($defaults['generic_no_match_ai_model'] ?? null) ?: ''),
                'ai_fallback_model' => (string) (($defaults['generic_no_match_ai_fallback_model'] ?? null) ?: ''),
                'ai_reasoning_effort' => (string) (($defaults['generic_no_match_ai_reasoning_effort'] ?? null) ?: ''),
            ]);
            $replyText = trim((string) ($aiResult['response'] ?? ''));
            if (!$this->isGenericAiReplyUsable($replyText)) {
                $this->logger->info('Generic no-match AI fallback skipped: response not usable.', [
                    'mailbox' => $mailbox['name'] ?? null,
                    'uid' => $message['uid'] ?? null,
                    'message_id' => $message['message_id'] ?? null,
                ]);

                return [
                    'handled' => false,
                    'reason' => 'no_matching_rule_generic_ai_unanswerable',
                ];
            }

            $replyText = $this->applyGenericFallbackFooter($mailbox, $replyText);
            $subjectPrefix = trim((string) (($defaults['generic_no_match_subject_prefix'] ?? null) ?: 'Re:'));
            $subject = $this->buildReplySubject((string) ($message['subject'] ?? ''), $subjectPrefix);
            $syntheticRule = [
                'reply' => [
                    'from_name' => (string) (($defaults['from_name'] ?? null) ?: ''),
                    'from_email' => (string) (($defaults['from_email'] ?? null) ?: ''),
                    'bcc' => (string) (($defaults['bcc'] ?? null) ?: ''),
                ],
            ];

            $this->sendReply($mailbox, $syntheticRule, $message, $subject, $replyText, $dryRun);
            $uid = (int) ($message['uid'] ?? 0);
            if ($uid > 0 && !$dryRun) {
                $imap->markSeen($uid);
            }

            $this->logger->info('Generic no-match AI fallback reply sent.', [
                'mailbox' => $mailbox['name'] ?? null,
                'uid' => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'dry_run' => $dryRun,
                'used_fallback_model' => !empty($aiResult['used_fallback_model']),
                'model' => $aiResult['model'] ?? null,
            ]);

            return [
                'handled' => true,
                'reason' => 'no_matching_rule_generic_ai_replied',
            ];
        } catch (Throwable $e) {
            $this->logger->warning('Generic no-match AI fallback failed.', [
                'mailbox' => $mailbox['name'] ?? null,
                'uid' => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'handled' => false,
                'reason' => 'no_matching_rule_generic_ai_error',
            ];
        }
    }

    private function isGenericNoMatchAiEnabled(array $config, array $mailbox): bool
    {
        $defaults = (array) ($mailbox['defaults'] ?? []);
        $candidates = [
            $defaults['generic_no_match_ai_enabled'] ?? null,
            $defaults['generic_reply_on_no_match'] ?? null,
            $defaults['generic_ai_reply_on_no_match'] ?? null,
            $mailbox['generic_no_match_ai_enabled'] ?? null,
            $config['generic_no_match_ai_enabled'] ?? null,
            $config['settings']['generic_no_match_ai_enabled'] ?? null,
            $config['settings']['generic_reply_on_no_match'] ?? null,
            $config['features']['generic_no_match_ai_enabled'] ?? null,
            Env::get('MAIL_ASSISTANT_GENERIC_NO_MATCH_AI', '0'),
        ];

        foreach ($candidates as $value) {
            $parsed = $this->toNullableBool($value);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return false;
    }

    private function toNullableBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }

        return null;
    }

    private function isGenericAiReplyUsable(string $text): bool
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return false;
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($normalized, 'UTF-8') < 24) {
                return false;
            }
        } elseif (strlen($normalized) < 24) {
            return false;
        }

        $lower = strtolower($normalized);
        $unanswerablePatterns = [
            '/^sorry[,\s]/',
            '/cannot assist/',
            '/can\'t assist/',
            '/do not have enough (information|context)/',
            '/i need more (information|details)/',
        ];
        foreach ($unanswerablePatterns as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return false;
            }
        }

        return true;
    }

    private function applyGenericFallbackFooter(array $mailbox, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $defaults = (array) ($mailbox['defaults'] ?? []);
        $footer = trim((string) (($defaults['generic_no_match_footer'] ?? null) ?: (($defaults['footer'] ?? null) ?: '')));
        if ($footer === '') {
            return $text;
        }

        return rtrim($text) . "\n\n" . $footer;
    }

    private function handleMessage(ImapMailboxClient $imap, array $mailbox, array $rule, array $message, bool $dryRun): void
    {
        $replyConfig = (array) ($rule['reply'] ?? []);
        if (!empty($replyConfig['enabled'])) {
            $replyText = $this->buildReplyText($mailbox, $rule, $message);
            $subject = $this->buildReplySubject((string) ($message['subject'] ?? ''), (string) (($replyConfig['subject_prefix'] ?? null) ?: 'Re:'));
            $this->sendReply($mailbox, $rule, $message, $subject, $replyText, $dryRun);
        }

        $postHandle = (array) ($rule['post_handle'] ?? []);
        $uid = (int) ($message['uid'] ?? 0);
        if ($uid < 1 || $dryRun) {
            return;
        }

        if (!empty($postHandle['delete_after_handle'])) {
            $imap->deleteMessage($uid);
            return;
        }

        $moveTo = trim((string) (($postHandle['move_to_folder'] ?? null) ?: ''));
        if ($moveTo !== '') {
            $imap->moveMessage($uid, $moveTo);
            return;
        }

        $imap->markSeen($uid);
    }

    private function buildReplyText(array $mailbox, array $rule, array $message): string
    {
        $replyConfig = (array) ($rule['reply'] ?? []);
        $text = '';

        if (!empty($replyConfig['ai_enabled'])) {
            try {
                $aiResult = $this->tools->generateAiReply($mailbox, $rule, $message);
                $text = trim((string) ($aiResult['response'] ?? ''));
            } catch (Throwable $e) {
                $this->logger->warning('AI reply generation failed, falling back to template.', ['error' => $e->getMessage()]);
            }
        }

        if ($text === '') {
            $template = (string) (($replyConfig['template_text'] ?? null) ?: 'Thank you for your message. We have reviewed it.');
            $text = strtr($template, [
                '{{from}}' => (string) ($message['from'] ?? ''),
                '{{to}}' => (string) ($message['to'] ?? ''),
                '{{subject}}' => (string) ($message['subject'] ?? ''),
                '{{body}}' => (string) ($message['body_text'] ?? ''),
            ]);
        }

        $footerMode = trim((string) (($replyConfig['footer_mode'] ?? null) ?: 'static'));
        if ($footerMode === 'static') {
            $footer = trim((string) (($replyConfig['footer_text'] ?? null) ?: (($mailbox['defaults']['footer'] ?? null) ?: '')));
            if ($footer !== '') {
                $text = rtrim($text) . "\n\n" . $footer;
            }
        }

        return trim($text);
    }

    private function evaluateSpamAssassin(array $message): array
    {
        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        if (empty($spam['present'])) {
            return [
                'skip' => false,
                'save_copy' => false,
                'reason' => '',
            ];
        }

        $tests = array_map('strtoupper', array_values((array) ($spam['tests'] ?? [])));
        $score = isset($spam['score']) ? (float) $spam['score'] : null;
        $skipScore = (float) Env::get('MAIL_ASSISTANT_SPAMASSASSIN_SKIP_SCORE', '8.0');
        $copyScore = (float) Env::get('MAIL_ASSISTANT_SPAMASSASSIN_COPY_SCORE', '5.0');
        $safeTests = [
            'ALL_TRUSTED',
            'USER_IN_WHITELIST',
            'USER_IN_DEF_WHITELIST',
            'SPF_PASS',
            'DKIM_VALID',
            'DKIM_SIGNED',
        ];
        $hasSafeSignal = count(array_intersect($tests, $safeTests)) > 0;
        $wrapperRemoved = !empty($message['spam_assassin_wrapper_removed']);
        $reportWrapper = !empty($spam['is_report_wrapper']) || $wrapperRemoved;
        $flagged = !empty($spam['flagged']);

        $shouldSaveCopy = $reportWrapper || ($flagged && $score !== null && $score >= $copyScore);
        $shouldSkip = $flagged
            && $score !== null
            && $score >= $skipScore
            && !$hasSafeSignal
            && !$reportWrapper;

        $reason = $shouldSkip
            ? 'high_score_without_safe_headers'
            : ($shouldSaveCopy ? ($reportWrapper ? 'spamassassin_wrapper_detected' : 'spamassassin_flagged_copy_saved') : '');

        return [
            'skip' => $shouldSkip,
            'save_copy' => $shouldSaveCopy,
            'reason' => $reason,
        ];
    }

    private function saveMessageCopy(array $mailbox, array $message, string $reason): void
    {
        ProjectPaths::ensureStorage();

        $mailboxId = (int) ($mailbox['id'] ?? 0);
        $uid = (int) ($message['uid'] ?? 0);
        $safeReason = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($reason))) ?: 'message-copy';
        $filename = sprintf(
            '%s%s%s-%s-mailbox-%d-uid-%d.json',
            ProjectPaths::messageCopies(),
            DIRECTORY_SEPARATOR,
            date('Ymd-His'),
            $safeReason,
            $mailboxId,
            $uid
        );

        $payload = [
            'saved_at' => date('c'),
            'reason' => $safeReason,
            'mailbox' => [
                'id' => $mailboxId,
                'name' => $mailbox['name'] ?? '',
            ],
            'message' => [
                'uid' => $uid,
                'message_id' => $message['message_id'] ?? '',
                'message_key' => $message['message_key'] ?? '',
                'subject' => $message['subject'] ?? '',
                'from' => $message['from'] ?? '',
                'to' => $message['to'] ?? '',
                'date' => $message['date'] ?? '',
                'headers_raw' => $message['headers_raw'] ?? '',
                'headers_map' => $message['headers_map'] ?? [],
                'body_text_raw' => $message['body_text_raw'] ?? '',
                'body_text' => $message['body_text'] ?? '',
                'spam_assassin' => $message['spam_assassin'] ?? [],
            ],
        ];

        @file_put_contents(
            $filename,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function resolveMessageKey(array $message): string
    {
        $messageKey = trim((string) ($message['message_key'] ?? ''));
        if ($messageKey !== '') {
            return $messageKey;
        }

        $messageId = trim((string) ($message['message_id'] ?? ''));
        if ($messageId !== '') {
            return strtolower($messageId);
        }

        return '';
    }

    private function recordMessageState(array &$mailboxSummary, array $message, string $status, string $reason, bool $dryRun): void
    {
        $messageKey = $this->resolveMessageKey($message);
        if ($messageKey === '') {
            return;
        }

        $mailboxId = (int) ($mailboxSummary['id'] ?? 0);
        $record = [
            'message_id' => (string) ($message['message_id'] ?? ''),
            'status' => $status,
            'reason' => $reason,
            'subject' => (string) ($message['subject'] ?? ''),
            'from' => (string) ($message['from'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
            'date' => (string) ($message['date'] ?? ''),
            'uid' => (int) ($message['uid'] ?? 0),
            'dry_run' => $dryRun,
        ];

        if (!$dryRun) {
            $this->messageState->remember($mailboxId, $messageKey, $record);
        }

        $mailboxSummary['message_state_records'][] = array_merge(['message_key' => $messageKey], $record);
    }

    private function buildReplySubject(string $subject, string $prefix): string
    {
        $prefix = trim($prefix) !== '' ? trim($prefix) : 'Re:';
        if (stripos($subject, $prefix) === 0) {
            return $subject;
        }

        return $prefix . ' ' . trim($subject);
    }

    private function sendReply(array $mailbox, array $rule, array $message, string $subject, string $body, bool $dryRun): void
    {
        $replyConfig = (array) ($rule['reply'] ?? []);
        $to = trim((string) ($message['from'] ?? ''));
        if ($to === '') {
            throw new RuntimeException('Cannot send reply without a valid From address.');
        }

        $fromName = trim((string) (($replyConfig['from_name'] ?? null) ?: (($mailbox['defaults']['from_name'] ?? null) ?: 'Mail Support Assistant')));
        $fromEmail = trim((string) (($replyConfig['from_email'] ?? null) ?: (($mailbox['defaults']['from_email'] ?? null) ?: '')));
        if ($fromEmail === '') {
            throw new RuntimeException('Cannot send reply because no From email is configured.');
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
        ];

        $inReplyTo = trim((string) ($message['message_id'] ?? ''));
        if ($inReplyTo !== '') {
            $headers[] = 'In-Reply-To: <' . trim($inReplyTo, "<> \t\n\r\0\x0B") . '>';
        }
        $references = array_values((array) ($message['references'] ?? []));
        if ($inReplyTo !== '' && !in_array($inReplyTo, $references, true)) {
            $references[] = $inReplyTo;
        }
        if (count($references)) {
            $headers[] = 'References: ' . implode(' ', array_map(static function (string $reference): string {
                return '<' . trim($reference, "<> \t\n\r\0\x0B") . '>';
            }, $references));
        }

        $bcc = trim((string) (($replyConfig['bcc'] ?? null) ?: (($mailbox['defaults']['bcc'] ?? null) ?: '')));
        if ($bcc !== '') {
            $headers[] = 'Bcc: ' . $bcc;
        }

        if ($dryRun) {
            $this->logger->info('DRY-RUN reply prepared.', ['to' => $to, 'subject' => $subject]);
            return;
        }

        $transportPlan = $this->buildMailTransportPlan();
        $attemptErrors = [];

        foreach ($transportPlan as $attemptIndex => $transport) {
            if ($transport === 'tools_api' && !$this->tools->hasMailToken()) {
                $this->logger->warning('Skipping Tools relay transport because MAIL_ASSISTANT_TOOLS_MAIL_TOKEN is not configured.', [
                    'to' => $to,
                    'subject' => $subject,
                    'attempt' => $attemptIndex + 1,
                    'transport' => $transport,
                ]);
                continue;
            }

            try {
                $this->deliverReplyViaTransport($transport, $mailbox, $rule, $message, $to, $subject, $body, $headers, $attemptIndex === 0);
                return;
            } catch (Throwable $transportError) {
                $attemptErrors[] = $transport . ': ' . $transportError->getMessage();
                $this->logger->warning('Mail transport attempt failed.', [
                    'to' => $to,
                    'subject' => $subject,
                    'attempt' => $attemptIndex + 1,
                    'transport' => $transport,
                    'error' => $transportError->getMessage(),
                ]);
            }
        }

        throw new RuntimeException(
            'All configured mail transports failed. ' . implode(' | ', array_values(array_unique($attemptErrors)))
        );
    }

    private function buildMailTransportPlan(): array
    {
        $allowed = ['smtp', 'pickup', 'php_mail', 'custom_mta', 'tools_api'];
        $primary = strtolower(trim((string) Env::get('MAIL_ASSISTANT_MAIL_TRANSPORT', 'smtp')));
        if (!in_array($primary, $allowed, true)) {
            $primary = 'smtp';
        }

        $plan = [$primary];

        $configuredFallbacks = trim((string) Env::get('MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS', ''));
        if ($configuredFallbacks !== '') {
            foreach (preg_split('/\s*,\s*/', $configuredFallbacks) ?: [] as $transport) {
                $transport = strtolower(trim((string) $transport));
                if ($transport !== '' && in_array($transport, $allowed, true)) {
                    $plan[] = $transport;
                }
            }
        } else {
            if ($primary === 'tools_api') {
                $plan = array_merge($plan, ['smtp', 'pickup', 'php_mail', 'custom_mta']);
            }

            if (Env::bool('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API', true)) {
                $plan[] = 'tools_api';
            }
        }

        $normalized = [];
        foreach ($plan as $transport) {
            if (!in_array($transport, $allowed, true) || in_array($transport, $normalized, true)) {
                continue;
            }
            $normalized[] = $transport;
        }

        return count($normalized) ? $normalized : ['smtp'];
    }

    private function deliverReplyViaTransport(string $transport, array $mailbox, array $rule, array $message, string $to, string $subject, string $body, array $headers, bool $isPrimary): void
    {
        if ($transport === 'tools_api') {
            $this->sendReplyViaToolsRelay(
                $mailbox,
                $rule,
                $message,
                $to,
                $subject,
                $body,
                $headers,
                $isPrimary ? 'tools_api_primary' : 'fallback_after_transport_failure'
            );
            return;
        }

        if ($transport === 'smtp') {
            $this->sendReplyViaSmtp($to, $subject, $body, $headers);
            $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'smtp']);
            return;
        }

        if ($transport === 'pickup') {
            $this->sendReplyViaPickup($to, $subject, $body, $headers);
            $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'pickup']);
            return;
        }

        if ($transport === 'custom_mta') {
            $this->sendReplyViaCustomMta($to, $subject, $body, $headers);
            $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'custom_mta']);
            return;
        }

        $this->sendReplyViaPhpMail($to, $subject, $body, $headers);
        $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'php_mail']);
    }

    private function sendReplyViaPhpMail(string $to, string $subject, string $body, array $headers): void
    {
        if (!@mail($to, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('PHP mail() failed while sending a reply.');
        }
    }

    private function sendReplyViaCustomMta(string $to, string $subject, string $body, array $headers): void
    {
        $command = trim((string) Env::get('MAIL_ASSISTANT_MTA_COMMAND', ''));
        if ($command === '') {
            throw new RuntimeException('MAIL_ASSISTANT_MTA_COMMAND is not configured.');
        }

        $process = @popen($command, 'w');
        if (!is_resource($process)) {
            throw new RuntimeException('Could not open custom MTA command: ' . $command);
        }

        $rawMessage = implode("\r\n", array_merge([
            'To: ' . $to,
            'Subject: ' . $subject,
        ], $headers, ['', $body]));

        fwrite($process, $rawMessage);
        $result = pclose($process);
        if ($result !== 0) {
            throw new RuntimeException('Custom MTA command failed with exit code ' . $result . '.');
        }
    }

    private function sendReplyViaPickup(string $to, string $subject, string $body, array $headers): void
    {
        $dir = rtrim(trim((string) Env::get('MAIL_ASSISTANT_PICKUP_DIR', '')), '/\\');
        if ($dir === '') {
            throw new RuntimeException('MAIL_ASSISTANT_PICKUP_DIR is not configured.');
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('MAIL_ASSISTANT_PICKUP_DIR is not a writable directory: ' . $dir);
        }

        $filename = $dir . DIRECTORY_SEPARATOR . 'mail-' . uniqid('', true) . '.msg';
        $rawMessage = implode("\r\n", array_merge([
            'To: ' . $to,
            'Subject: ' . $subject,
        ], $headers, ['', $body]));

        if (@file_put_contents($filename, $rawMessage) === false) {
            throw new RuntimeException('Could not write message to pickup directory: ' . $filename);
        }
    }

    private function sendReplyViaSmtp(string $to, string $subject, string $body, array $headers): void
    {
        $host = trim((string) Env::get('MAIL_ASSISTANT_SMTP_HOST', ''));
        $port = (int) Env::get('MAIL_ASSISTANT_SMTP_PORT', '587');
        $security = strtolower(trim((string) Env::get('MAIL_ASSISTANT_SMTP_SECURITY', 'tls')));
        $username = trim((string) Env::get('MAIL_ASSISTANT_SMTP_USERNAME', ''));
        $password = (string) Env::get('MAIL_ASSISTANT_SMTP_PASSWORD', '');
        $ehlo = trim((string) Env::get('MAIL_ASSISTANT_SMTP_EHLO', 'localhost'));
        $timeout = max(5, (int) Env::get('MAIL_ASSISTANT_SMTP_TIMEOUT', '20'));

        if ($host === '') {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_HOST is not configured.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_PORT is invalid.');
        }
        if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_SECURITY must be tls, ssl, or none.');
        }

        $fromHeader = $this->extractHeaderValue($headers, 'From');
        $fromEmail = $this->extractFirstEmail($fromHeader);
        if ($fromEmail === '') {
            throw new RuntimeException('Could not resolve sender email from From header for SMTP delivery.');
        }

        $envelopeFrom = trim((string) Env::get('MAIL_ASSISTANT_SMTP_FROM_ENVELOPE', $fromEmail));
        if (!filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_FROM_ENVELOPE is invalid.');
        }

        $envelopeRecipients = array_values(array_unique(array_filter(array_merge(
            [$to],
            $this->extractEmailsFromHeaderValue($this->extractHeaderValue($headers, 'Cc')),
            $this->extractEmailsFromHeaderValue($this->extractHeaderValue($headers, 'Bcc'))
        ))));
        if (!count($envelopeRecipients)) {
            throw new RuntimeException('No valid SMTP recipients resolved.');
        }

        $transportHost = $security === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connect failed: ' . trim($errstr . ' (' . $errno . ')'));
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->smtpExpect($socket, [220], 'SMTP greeting');
            $this->smtpCommand($socket, 'EHLO ' . $ehlo, [250], 'SMTP EHLO');

            if ($security === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', [220], 'SMTP STARTTLS');
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS handshake failed.');
                }
                $this->smtpCommand($socket, 'EHLO ' . $ehlo, [250], 'SMTP EHLO after STARTTLS');
            }

            if ($username !== '' || $password !== '') {
                if ($username === '' || $password === '') {
                    throw new RuntimeException('MAIL_ASSISTANT_SMTP_USERNAME and MAIL_ASSISTANT_SMTP_PASSWORD must both be set for SMTP auth.');
                }
                $this->smtpCommand($socket, 'AUTH LOGIN', [334], 'SMTP AUTH LOGIN start');
                $this->smtpCommand($socket, base64_encode($username), [334], 'SMTP AUTH LOGIN username');
                $this->smtpCommand($socket, base64_encode($password), [235], 'SMTP AUTH LOGIN password');
            }

            $this->smtpCommand($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250], 'SMTP MAIL FROM');
            foreach ($envelopeRecipients as $recipient) {
                $this->smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], 'SMTP RCPT TO');
            }

            $this->smtpCommand($socket, 'DATA', [354], 'SMTP DATA');

            $dataHeaders = [];
            foreach ($headers as $header) {
                if (stripos($header, 'Bcc:') === 0) {
                    continue;
                }
                $dataHeaders[] = $header;
            }

            $raw = implode("\r\n", array_merge([
                'To: ' . $to,
                'Subject: ' . $subject,
            ], $dataHeaders, ['', $body]));
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $raw = preg_replace('/^\./m', '..', $raw) ?? $raw;
            $raw = str_replace("\n", "\r\n", $raw);

            fwrite($socket, $raw . "\r\n.\r\n");
            $this->smtpExpect($socket, [250], 'SMTP message body');
            $this->smtpCommand($socket, 'QUIT', [221], 'SMTP QUIT');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function smtpCommand($socket, string $command, array $expectedCodes, string $context): string
    {
        fwrite($socket, $command . "\r\n");

        return $this->smtpExpect($socket, $expectedCodes, $context);
    }

    private function smtpExpect($socket, array $expectedCodes, string $context): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }

        $responseTrimmed = trim($response);
        if ($responseTrimmed === '') {
            throw new RuntimeException($context . ' failed: empty SMTP response.');
        }

        $code = (int) substr($responseTrimmed, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException($context . ' failed: ' . $responseTrimmed);
        }

        return $responseTrimmed;
    }

    private function extractHeaderValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, $name . ':') === 0) {
                return trim((string) substr((string) $header, strlen($name) + 1));
            }
        }

        return '';
    }

    private function extractFirstEmail(string $headerValue): string
    {
        $emails = $this->extractEmailsFromHeaderValue($headerValue);

        return $emails[0] ?? '';
    }

    private function extractEmailsFromHeaderValue(string $headerValue): array
    {
        $result = [];
        foreach (preg_split('/,/', $headerValue) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/<([^>]+)>/', $part, $m) === 1) {
                $email = trim((string) $m[1]);
            } else {
                $email = trim($part, '"\' ');
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result[] = strtolower($email);
            }
        }

        return array_values(array_unique($result));
    }

    private function sendReplyViaToolsRelay(
        array $mailbox,
        array $rule,
        array $message,
        string $to,
        string $subject,
        string $body,
        array $headers,
        string $mode
    ): void {
        $fromHeader = '';
        foreach ($headers as $header) {
            if (stripos($header, 'From:') === 0) {
                $fromHeader = trim(substr($header, 5));
                break;
            }
        }

        $cc = [];
        $bcc = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Cc:') === 0) {
                $cc[] = trim(substr($header, 3));
            }
            if (stripos($header, 'Bcc:') === 0) {
                $bcc[] = trim(substr($header, 4));
            }
        }

        $this->tools->sendReplyViaTools([
            'mailbox_id' => (int) ($mailbox['id'] ?? 0),
            'rule_id' => (int) ($rule['id'] ?? 0),
            'mode' => $mode,
            'to' => $to,
            'cc' => array_values(array_filter($cc)),
            'bcc' => array_values(array_filter($bcc)),
            'from' => $fromHeader,
            'subject' => $subject,
            'body' => $body,
            'message_meta' => [
                'message_id' => (string) ($message['message_id'] ?? ''),
                'uid' => (int) ($message['uid'] ?? 0),
                'from' => (string) ($message['from'] ?? ''),
                'to' => (string) ($message['to'] ?? ''),
                'date' => (string) ($message['date'] ?? ''),
            ],
        ]);

        $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'tools_api', 'mode' => $mode]);
    }
}


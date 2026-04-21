<?php

namespace MailSupportAssistant\Runner;

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Mail\MimeDecoder;
use MailSupportAssistant\Support\Env;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Support\ProjectPaths;
use MailSupportAssistant\Tools\ToolsApiClient;
use RuntimeException;
use Throwable;

class MailAssistantRunner
{
    private const ASSISTANT_SENT_HEADER = 'X-Tornevall-Mail-Assistant';
    private const ASSISTANT_SENT_HEADER_VALUE = 'sent';

    private ToolsApiClient $tools;
    private Logger $logger;
    private MessageStateStore $messageState;
    private bool $includeHistory = false;

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
        $this->includeHistory = !empty($options['include_history']);
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
            'messages_failed' => 0,
            'messages_spamassassin_skipped' => 0,
            'messages_reply_spam_score_suppressed' => 0,
            'messages_assistant_sent_skipped' => 0,
            'messages_read_skipped' => 0,
            'spamassassin_copies_saved' => 0,
            'errors' => [],
            'mailboxes' => [],
        ];
        if ($this->includeHistory) {
            $summary['messages_previously_recorded_unread'] = 0;
            $summary['message_state'] = $this->messageState->summary();
            $summary['message_state_mode'] = 'on_demand';
        }

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
                'failed' => 0,
                'spamassassin_skipped' => 0,
                'reply_spam_score_suppressed' => 0,
                'assistant_sent_skipped' => 0,
                'read_skipped' => 0,
                'spamassassin_copies_saved' => 0,
                'message_results' => [],
                'errors' => [],
            ];
            if ($this->includeHistory) {
                $mailboxSummary['previously_recorded_unread'] = 0;
                $mailboxSummary['message_state_records'] = [];
            }

            try {
                $imap = $this->createImapMailboxClient((array) ($mailbox['imap'] ?? []));
                $messages = $imap->fetchUnseenMessages($limitOverride ?: (int) (($mailbox['defaults']['run_limit'] ?? 20) ?: 20));
                $mailboxSummary['scanned'] = count($messages);
                $summary['messages_scanned'] += count($messages);

                foreach ($messages as $message) {
                    try {
                        $this->logger->info('Processing message.', [
                            'mailbox' => $mailbox['name'] ?? null,
                            'uid' => $message['uid'] ?? null,
                            'message_id' => $message['message_id'] ?? null,
                            'subject' => $message['subject'] ?? null,
                            'from' => $message['from'] ?? null,
                        ]);

                        if (!empty($message['is_seen'])) {
                            $mailboxSummary['skipped']++;
                            $mailboxSummary['read_skipped']++;
                            $summary['messages_skipped']++;
                            $summary['messages_read_skipped']++;
                            $this->recordMessageResult($mailboxSummary, $message, 'skipped', 'already_read_at_ingest');
                            $this->logger->info('Message skipped because it was already marked read at ingest.', [
                                'mailbox' => $mailbox['name'] ?? null,
                                'uid' => $message['uid'] ?? null,
                                'message_id' => $message['message_id'] ?? null,
                            ]);
                            continue;
                        }

                        if ($this->isAssistantSentMessage($message)) {
                            $mailboxSummary['skipped']++;
                            $mailboxSummary['assistant_sent_skipped']++;
                            $summary['messages_skipped']++;
                            $summary['messages_assistant_sent_skipped']++;
                            $this->recordMessageState($mailboxSummary, $message, 'ignored', 'assistant_sent_marker', $dryRun);
                            $this->recordMessageResult($mailboxSummary, $message, 'skipped', 'assistant_sent_marker');
                            $this->logger->info('Message skipped because assistant marker header is present (anti-loop guard).', [
                                'mailbox' => $mailbox['name'] ?? null,
                                'uid' => $message['uid'] ?? null,
                                'message_id' => $message['message_id'] ?? null,
                            ]);
                            if (!$dryRun) {
                                $imap->markSeen((int) $message['uid']);
                            }
                            continue;
                        }

                        $messageKey = $this->resolveMessageKey($message);
                        $messageId = (string) ($message['message_id'] ?? '');
                        $priorState = null;
                        if ($this->includeHistory && $messageKey !== '') {
                            $priorState = $this->messageState->getRecord((int) $mailboxSummary['id'], $messageKey);
                        }
                        if (is_array($priorState)) {
                            if ($this->includeHistory) {
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
                        }

                        $message['thread_context'] = $this->messageState->summarizeThread((int) $mailboxSummary['id'], $message);


                        $spamDecision = $this->evaluateSpamAssassin($message);
                        if (!empty($spamDecision['save_copy'])) {
                            $this->saveMessageCopy($mailbox, $message, (string) ($spamDecision['reason'] ?? 'spamassassin_copy'));
                            $mailboxSummary['spamassassin_copies_saved']++;
                            $summary['spamassassin_copies_saved']++;
                        }

                        // Normalize subject: remove configured prefixes
                        $message['subject'] = $this->normalizeSubjectLine($message['subject'], $mailbox);

                        if (!empty($spamDecision['skip'])) {
                            $mailboxSummary['skipped']++;
                            $mailboxSummary['spamassassin_skipped']++;
                            $summary['messages_skipped']++;
                            $summary['messages_spamassassin_skipped']++;
                            $this->recordMessageState($mailboxSummary, $message, 'ignored', (string) ($spamDecision['reason'] ?? 'spamassassin_skip'), $dryRun);
                            $this->recordMessageResult($mailboxSummary, $message, 'skipped', (string) ($spamDecision['reason'] ?? 'spamassassin_skip'));
                            $this->logger->info('Message skipped due to SpamAssassin heuristic.', [
                                'mailbox' => $mailbox['name'] ?? null,
                                'uid' => $message['uid'] ?? null,
                                'reason' => $spamDecision['reason'] ?? null,
                                'score' => $message['spam_assassin']['score'] ?? null,
                            ]);
                            if ($this->shouldMarkSeenOnSkip($mailbox, (string) ($spamDecision['reason'] ?? '')) && !$dryRun) {
                                $imap->markSeen((int) $message['uid']);
                            } else {
                                $this->preserveUnreadState($imap, $message, $dryRun, 'spamassassin_skip_preserve_unread');
                            }
                            continue;
                        }

                        $replySpamScoreDecision = $this->evaluateReplySpamScoreThreshold($mailbox, $message);
                        if (!empty($replySpamScoreDecision['skip_reply'])) {
                            $mailboxSummary['skipped']++;
                            $mailboxSummary['reply_spam_score_suppressed']++;
                            $summary['messages_skipped']++;
                            $summary['messages_reply_spam_score_suppressed']++;
                            $skipReason = (string) ($replySpamScoreDecision['reason'] ?? 'spam_score_reply_threshold_exceeded');
                            $this->recordMessageState($mailboxSummary, $message, 'ignored', $skipReason, $dryRun, [
                                'reply_spam_score_threshold' => $replySpamScoreDecision['threshold'] ?? null,
                                'reply_spam_score' => $replySpamScoreDecision['score'] ?? null,
                            ]);
                            $this->recordMessageResult($mailboxSummary, $message, 'skipped', $skipReason, [
                                'reply_spam_score_threshold' => $replySpamScoreDecision['threshold'] ?? null,
                                'reply_spam_score' => $replySpamScoreDecision['score'] ?? null,
                            ]);
                            $this->logger->info('Message reply suppressed by mailbox spam score threshold and kept unread.', [
                                'mailbox' => $mailbox['name'] ?? null,
                                'uid' => $message['uid'] ?? null,
                                'message_id' => $message['message_id'] ?? null,
                                'threshold' => $replySpamScoreDecision['threshold'] ?? null,
                                'score' => $replySpamScoreDecision['score'] ?? null,
                            ]);
                            $this->preserveUnreadState($imap, $message, $dryRun, 'spam_score_reply_threshold_preserve_unread');
                            continue;
                        }

                            $ruleMatches = $this->findMatchingRules($message, (array) ($mailbox['rules'] ?? []));
                            $selectedRuleMatch = $this->selectBestRuleMatch($ruleMatches);
                            $ruleResolution = [
                                'source' => $selectedRuleMatch ? 'direct_rule_match' : 'no_direct_rule_match',
                                'reused_from_message_id' => '',
                                'reused_from_reason' => '',
                            ];
                            $threadReuse = null;
                            if (!$selectedRuleMatch) {
                                $threadReuse = $this->resolveHistoricalRuleReuse((int) ($mailboxSummary['id'] ?? 0), $mailbox, $message);
                                if (($threadReuse['type'] ?? null) === 'selected_rule' && is_array($threadReuse['rule'] ?? null)) {
                                    $selectedRuleMatch = [
                                        'matched' => true,
                                        'rule' => (array) $threadReuse['rule'],
                                        'matched_fields' => [],
                                        'active_criteria_count' => 0,
                                        'specificity_length' => 0,
                                        'match_priority_score' => 0,
                                    ];
                                    $ruleResolution = [
                                        'source' => (string) ($threadReuse['source'] ?? 'thread_history_selected_rule'),
                                        'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
                                        'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
                                    ];
                                    $this->logger->info('No direct rule matched; reusing the previously handled thread rule from local history.', [
                                        'mailbox' => $mailbox['name'] ?? null,
                                        'uid' => $message['uid'] ?? null,
                                        'message_id' => $message['message_id'] ?? null,
                                        'reused_from_message_id' => $threadReuse['record']['message_id'] ?? null,
                                        'reused_from_reason' => $threadReuse['record']['reason'] ?? null,
                                        'selected_rule_id' => $threadReuse['rule']['id'] ?? null,
                                        'selected_rule_name' => $threadReuse['rule']['name'] ?? null,
                                    ]);
                                }
                            }
                            if (!$selectedRuleMatch) {
                            $genericNoMatch = $this->tryHandleGenericNoMatch($imap, $mailbox, $message, $dryRun, is_array($threadReuse) ? $threadReuse : null);
                            if (!empty($genericNoMatch['handled'])) {
                                    $genericReason = (string) ($genericNoMatch['reason'] ?? 'no_matching_rule_generic_ai_replied');
                                    $this->recordMessageState($mailboxSummary, $message, 'handled', (string) ($genericNoMatch['reason'] ?? 'no_matching_rule_generic_ai_replied'), $dryRun, [
                                        'matching_rule_count' => 0,
                                        'matching_rules' => [],
                                        'selected_rule' => null,
                                        'post_handle_action' => (string) ($genericNoMatch['post_handle_action'] ?? ''),
                                        'post_handle_warning' => (string) ($genericNoMatch['post_handle_warning'] ?? ''),
                                        'generic_ai_decision' => (array) ($genericNoMatch['ai_decision'] ?? []),
                                        'rule_resolution_source' => (string) ($genericNoMatch['rule_resolution_source'] ?? (($threadReuse['source'] ?? null) ?: 'generic_no_match')),
                                        'reused_from_message_id' => (string) ($genericNoMatch['reused_from_message_id'] ?? (($threadReuse['record']['message_id'] ?? null) ?: '')),
                                        'reused_from_reason' => (string) ($genericNoMatch['reused_from_reason'] ?? (($threadReuse['record']['reason'] ?? null) ?: '')),
                                    ]);
                                $this->recordMessageResult($mailboxSummary, $message, !empty($genericNoMatch['post_handle_warning']) ? 'warning' : 'handled', $genericReason, [
                                    'matching_rule_count' => 0,
                                    'matching_rules' => [],
                                    'selected_rule' => null,
                                    'post_handle_action' => (string) ($genericNoMatch['post_handle_action'] ?? ''),
                                    'post_handle_warning' => (string) ($genericNoMatch['post_handle_warning'] ?? ''),
                                    'generic_ai_decision' => (array) ($genericNoMatch['ai_decision'] ?? []),
                                    'reply_message_id' => (string) ($genericNoMatch['reply_message_id'] ?? ''),
                                    'reply_transport' => (string) ($genericNoMatch['reply_transport'] ?? ''),
                                    'rule_resolution_source' => (string) ($genericNoMatch['rule_resolution_source'] ?? (($threadReuse['source'] ?? null) ?: 'generic_no_match')),
                                    'reused_from_message_id' => (string) ($genericNoMatch['reused_from_message_id'] ?? (($threadReuse['record']['message_id'] ?? null) ?: '')),
                                    'reused_from_reason' => (string) ($genericNoMatch['reused_from_reason'] ?? (($threadReuse['record']['reason'] ?? null) ?: '')),
                                ]);
                                if (!empty($genericNoMatch['post_handle_warning'])) {
                                    $this->logger->warning('Generic no-match reply was sent, but mailbox finalize step needs attention.', [
                                        'mailbox' => $mailbox['name'] ?? null,
                                        'uid' => $message['uid'] ?? null,
                                        'message_id' => $message['message_id'] ?? null,
                                        'warning' => $genericNoMatch['post_handle_warning'] ?? null,
                                    ]);
                                }
                                $mailboxSummary['handled']++;
                                $summary['messages_handled']++;
                                continue;
                            }

                            $mailboxSummary['skipped']++;
                            $summary['messages_skipped']++;
                                $this->recordMessageState($mailboxSummary, $message, 'ignored', (string) ($genericNoMatch['reason'] ?? 'no_matching_rule'), $dryRun, [
                                    'matching_rule_count' => 0,
                                    'matching_rules' => [],
                                    'selected_rule' => null,
                                    'generic_ai_decision' => (array) ($genericNoMatch['ai_decision'] ?? []),
                                    'rule_resolution_source' => (string) ($genericNoMatch['rule_resolution_source'] ?? (($threadReuse['source'] ?? null) ?: 'generic_no_match')),
                                    'reused_from_message_id' => (string) ($genericNoMatch['reused_from_message_id'] ?? (($threadReuse['record']['message_id'] ?? null) ?: '')),
                                    'reused_from_reason' => (string) ($genericNoMatch['reused_from_reason'] ?? (($threadReuse['record']['reason'] ?? null) ?: '')),
                                ]);
                            $this->recordMessageResult($mailboxSummary, $message, 'skipped', (string) ($genericNoMatch['reason'] ?? 'no_matching_rule'), [
                                'matching_rule_count' => 0,
                                'matching_rules' => [],
                                'selected_rule' => null,
                                'generic_ai_decision' => (array) ($genericNoMatch['ai_decision'] ?? []),
                                'rule_resolution_source' => (string) ($genericNoMatch['rule_resolution_source'] ?? (($threadReuse['source'] ?? null) ?: 'generic_no_match')),
                                'reused_from_message_id' => (string) ($genericNoMatch['reused_from_message_id'] ?? (($threadReuse['record']['message_id'] ?? null) ?: '')),
                                'reused_from_reason' => (string) ($genericNoMatch['reused_from_reason'] ?? (($threadReuse['record']['reason'] ?? null) ?: '')),
                            ]);
                            $this->logger->info('Message skipped because no configured rule matched.', [
                                'mailbox' => $mailbox['name'] ?? null,
                                'uid' => $message['uid'] ?? null,
                                'message_id' => $message['message_id'] ?? null,
                                'thread_key' => $this->resolveThreadKey($message),
                                'in_reply_to' => $message['in_reply_to'] ?? null,
                                'references' => array_values((array) ($message['references'] ?? [])),
                                'subject' => $message['subject'] ?? null,
                                'subject_normalized' => $message['subject_normalized'] ?? null,
                                'from' => $message['from'] ?? null,
                                'to' => $message['to'] ?? null,
                                'thread_context_messages' => count((array) (($message['thread_context']['messages'] ?? null) ?: [])),
                                'rule_resolution_source' => $genericNoMatch['rule_resolution_source'] ?? (($threadReuse['source'] ?? null) ?: 'generic_no_match'),
                                'reused_from_message_id' => $genericNoMatch['reused_from_message_id'] ?? (($threadReuse['record']['message_id'] ?? null) ?: null),
                                'generic_no_match_reason' => $genericNoMatch['reason'] ?? null,
                                'generic_ai_decision' => $genericNoMatch['ai_decision'] ?? null,
                            ]);
                            if ($this->shouldMarkSeenOnSkip($mailbox, (string) ($genericNoMatch['reason'] ?? '')) && !$dryRun) {
                                $imap->markSeen((int) $message['uid']);
                            } else {
                                $this->preserveUnreadState($imap, $message, $dryRun, 'generic_no_match_preserve_unread');
                            }
                            continue;
                        }

                            $selectedRule = (array) ($selectedRuleMatch['rule'] ?? []);
                            $selectedRuleSummary = $this->buildRuleMatchSummary($selectedRuleMatch);
                            $matchingRuleSummaries = array_map(function (array $match): array {
                                return $this->buildRuleMatchSummary($match);
                            }, $ruleMatches);

                            if (count($ruleMatches) > 1) {
                                $this->logger->warning('Multiple rules matched the same message; selected the highest-priority winner.', [
                                    'mailbox' => $mailbox['name'] ?? null,
                                    'uid' => $message['uid'] ?? null,
                                    'message_id' => $message['message_id'] ?? null,
                                    'selected_rule' => $selectedRuleSummary,
                                    'matching_rules' => $matchingRuleSummaries,
                                ]);
                            } else {
                                $this->logger->info('Message matched a rule.', [
                                    'mailbox' => $mailbox['name'] ?? null,
                                    'uid' => $message['uid'] ?? null,
                                    'message_id' => $message['message_id'] ?? null,
                                    'selected_rule' => $selectedRuleSummary,
                                ]);
                            }

                            $handleResult = $this->handleMessage($imap, $mailbox, $selectedRule, $message, $dryRun);
                            if (empty($handleResult['handled'])) {
                                $mailboxSummary['skipped']++;
                                $summary['messages_skipped']++;
                                $skipReason = (string) ($handleResult['reason'] ?? 'rule_matched_reply_not_sent');
                                $this->recordMessageState($mailboxSummary, $message, 'ignored', $skipReason, $dryRun, [
                                    'matching_rule_count' => count($matchingRuleSummaries),
                                    'matching_rules' => $matchingRuleSummaries,
                                    'selected_rule' => $selectedRuleSummary,
                                    'rule_resolution_source' => (string) ($ruleResolution['source'] ?? 'direct_rule_match'),
                                    'reused_from_message_id' => (string) ($ruleResolution['reused_from_message_id'] ?? ''),
                                    'reused_from_reason' => (string) ($ruleResolution['reused_from_reason'] ?? ''),
                                    'reply_message_id' => (string) ($handleResult['reply_message_id'] ?? ''),
                                    'reply_transport' => (string) ($handleResult['reply_transport'] ?? ''),
                                    'post_handle_warning' => (string) ($handleResult['post_handle_warning'] ?? ''),
                                    'post_handle_action' => (string) ($handleResult['post_handle_action'] ?? ''),
                                    'reply_sent' => false,
                                ]);
                                $this->recordMessageResult($mailboxSummary, $message, 'skipped', $skipReason, [
                                    'matching_rule_count' => count($matchingRuleSummaries),
                                    'matching_rules' => $matchingRuleSummaries,
                                    'selected_rule' => $selectedRuleSummary,
                                    'rule_resolution_source' => (string) ($ruleResolution['source'] ?? 'direct_rule_match'),
                                    'reused_from_message_id' => (string) ($ruleResolution['reused_from_message_id'] ?? ''),
                                    'reused_from_reason' => (string) ($ruleResolution['reused_from_reason'] ?? ''),
                                    'reply_message_id' => (string) ($handleResult['reply_message_id'] ?? ''),
                                    'reply_transport' => (string) ($handleResult['reply_transport'] ?? ''),
                                    'post_handle_warning' => (string) ($handleResult['post_handle_warning'] ?? ''),
                                    'post_handle_action' => (string) ($handleResult['post_handle_action'] ?? ''),
                                    'reply_sent' => false,
                                ]);
                                $this->logger->warning('Message matched a rule, but no reply was sent so the message was intentionally left unread.', [
                                    'mailbox' => $mailbox['name'] ?? null,
                                    'uid' => $message['uid'] ?? null,
                                    'message_id' => $message['message_id'] ?? null,
                                    'selected_rule' => $selectedRuleSummary,
                                    'reason' => $skipReason,
                                ]);
                                $this->preserveUnreadState($imap, $message, $dryRun, 'reply_not_sent_preserve_unread');
                                continue;
                            }

                            $handledReasonBase = !empty($handleResult['reply_sent'])
                                ? 'rule_matched_replied'
                                : 'rule_matched_processed_without_reply';
                            $handledReason = !empty($handleResult['post_handle_warning'])
                                ? ($handledReasonBase . '_imap_finalize_failed')
                                : $handledReasonBase;
                            $this->recordMessageState($mailboxSummary, $message, 'handled', $handledReason, $dryRun, [
                                'matching_rule_count' => count($matchingRuleSummaries),
                                'matching_rules' => $matchingRuleSummaries,
                                'selected_rule' => $selectedRuleSummary,
                                'rule_resolution_source' => (string) ($ruleResolution['source'] ?? 'direct_rule_match'),
                                'reused_from_message_id' => (string) ($ruleResolution['reused_from_message_id'] ?? ''),
                                'reused_from_reason' => (string) ($ruleResolution['reused_from_reason'] ?? ''),
                                'reply_message_id' => (string) ($handleResult['reply_message_id'] ?? ''),
                                'reply_transport' => (string) ($handleResult['reply_transport'] ?? ''),
                                'post_handle_warning' => (string) ($handleResult['post_handle_warning'] ?? ''),
                                'post_handle_action' => (string) ($handleResult['post_handle_action'] ?? ''),
                            ]);
                        $this->recordMessageResult($mailboxSummary, $message, !empty($handleResult['post_handle_warning']) ? 'warning' : 'handled', $handledReason, [
                            'matching_rule_count' => count($matchingRuleSummaries),
                            'matching_rules' => $matchingRuleSummaries,
                            'selected_rule' => $selectedRuleSummary,
                            'rule_resolution_source' => (string) ($ruleResolution['source'] ?? 'direct_rule_match'),
                            'reused_from_message_id' => (string) ($ruleResolution['reused_from_message_id'] ?? ''),
                            'reused_from_reason' => (string) ($ruleResolution['reused_from_reason'] ?? ''),
                            'reply_message_id' => (string) ($handleResult['reply_message_id'] ?? ''),
                            'reply_transport' => (string) ($handleResult['reply_transport'] ?? ''),
                            'post_handle_warning' => (string) ($handleResult['post_handle_warning'] ?? ''),
                            'post_handle_action' => (string) ($handleResult['post_handle_action'] ?? ''),
                            'reply_sent' => !empty($handleResult['reply_sent']),
                        ]);
                        if (!empty($handleResult['post_handle_warning'])) {
                            $this->logger->warning('Reply was sent, but mailbox finalize step needs attention.', [
                                'mailbox' => $mailbox['name'] ?? null,
                                'uid' => $message['uid'] ?? null,
                                'message_id' => $message['message_id'] ?? null,
                                'selected_rule' => $selectedRuleSummary,
                                'warning' => $handleResult['post_handle_warning'] ?? null,
                                'post_handle_action' => $handleResult['post_handle_action'] ?? null,
                            ]);
                        }
                        $mailboxSummary['handled']++;
                        $summary['messages_handled']++;
                    } catch (Throwable $messageError) {
                        $summary['ok'] = false;
                        $mailboxSummary['failed']++;
                        $summary['messages_failed']++;
                        $messageUid = (int) ($message['uid'] ?? 0);
                        $messageSubject = (string) ($message['subject'] ?? '');
                        $messageLabel = $messageUid > 0
                            ? ('UID ' . $messageUid)
                            : (trim($messageSubject) !== '' ? trim($messageSubject) : 'unknown message');
                        $mailboxSummary['errors'][] = sprintf(
                            'Message %s failed: %s',
                            $messageLabel,
                            $messageError->getMessage()
                        );
                        $summary['errors'][] = sprintf(
                            'Mailbox %s message %s: %s',
                            (string) ($mailbox['name'] ?? 'unknown'),
                            $messageLabel,
                            $messageError->getMessage()
                        );
                        $this->recordMessageState($mailboxSummary, $message, 'error', 'message_handling_failed', $dryRun, [
                            'error' => $messageError->getMessage(),
                        ]);
                        $this->recordMessageResult($mailboxSummary, $message, 'error', 'message_handling_failed', [
                            'error' => $messageError->getMessage(),
                        ]);
                        $this->logger->error('Message handling failed.', [
                            'mailbox' => $mailbox['name'] ?? null,
                            'uid' => $message['uid'] ?? null,
                            'message_id' => $message['message_id'] ?? null,
                            'subject' => $message['subject'] ?? null,
                            'error' => $messageError->getMessage(),
                        ]);
                    }
                }
            } catch (Throwable $e) {
                $mailboxSummary['errors'][] = $e->getMessage();
                $summary['errors'][] = sprintf('Mailbox %s: %s', (string) ($mailbox['name'] ?? 'unknown'), $e->getMessage());
                $summary['ok'] = false;
                $this->logger->error('Mailbox run failed.', ['mailbox' => $mailbox['name'] ?? null, 'error' => $e->getMessage()]);
            }

            $summary['mailboxes'][] = $mailboxSummary;
        }

        if ($this->includeHistory) {
            $summary['message_state'] = $this->messageState->summary();
        }

        $this->logger->info('Mail assistant run completed.', [
            'dry_run' => $dryRun,
            'mailboxes_total' => $summary['mailboxes_total'],
            'messages_scanned' => $summary['messages_scanned'],
            'messages_handled' => $summary['messages_handled'],
            'messages_skipped' => $summary['messages_skipped'],
            'messages_failed' => $summary['messages_failed'],
            'messages_spamassassin_skipped' => $summary['messages_spamassassin_skipped'],
            'messages_reply_spam_score_suppressed' => $summary['messages_reply_spam_score_suppressed'],
            'messages_assistant_sent_skipped' => $summary['messages_assistant_sent_skipped'],
            'messages_read_skipped' => $summary['messages_read_skipped'],
            'spamassassin_copies_saved' => $summary['spamassassin_copies_saved'],
            'errors' => count($summary['errors']),
            'include_history' => $this->includeHistory,
        ]);
        $this->logger->saveLastRun($summary);

        return $summary;
    }

    private function wasPreviouslyRepliedState(?array $priorState): bool
    {
        if (!is_array($priorState)) {
            return false;
        }

        if (strtolower(trim((string) ($priorState['status'] ?? ''))) !== 'handled') {
            return false;
        }

        $reason = strtolower(trim((string) ($priorState['reason'] ?? '')));

        return in_array($reason, [
            'rule_matched_replied',
            'rule_matched_replied_imap_finalize_failed',
            'no_matching_rule_generic_ai_replied',
            'no_matching_rule_generic_ai_replied_imap_finalize_failed',
        ], true);
    }

    private function findMatchingRules(array $message, array $rules): array
    {
        $messageFields = [
            'from_contains' => (string) ($message['from'] ?? ''),
            'to_contains' => (string) ($message['to'] ?? ''),
            'subject_contains' => (string) (($message['subject_normalized'] ?? null) ?: ($message['subject'] ?? '')),
            'body_contains' => (string) (($message['body_text_reply_aware'] ?? null) ?: ($message['body_text'] ?? '')),
        ];
        $matches = [];

        foreach ($rules as $rule) {
            $match = $this->evaluateRuleMatch($messageFields, (array) $rule);
            if (!empty($match['matched'])) {
                $matches[] = $match;
            }
        }

        usort($matches, function (array $left, array $right): int {
            $sortDiff = ((int) (($left['rule']['sort_order'] ?? 0))) <=> ((int) (($right['rule']['sort_order'] ?? 0)));
            if ($sortDiff !== 0) {
                return $sortDiff;
            }

            $priorityDiff = ((int) ($right['match_priority_score'] ?? 0)) <=> ((int) ($left['match_priority_score'] ?? 0));
            if ($priorityDiff !== 0) {
                return $priorityDiff;
            }

            $criteriaDiff = ((int) ($right['active_criteria_count'] ?? 0)) <=> ((int) ($left['active_criteria_count'] ?? 0));
            if ($criteriaDiff !== 0) {
                return $criteriaDiff;
            }

            $lengthDiff = ((int) ($right['specificity_length'] ?? 0)) <=> ((int) ($left['specificity_length'] ?? 0));
            if ($lengthDiff !== 0) {
                return $lengthDiff;
            }

            return ((int) (($left['rule']['id'] ?? 0))) <=> ((int) (($right['rule']['id'] ?? 0)));
        });

        return $matches;
    }

    private function selectBestRuleMatch(array $matches): ?array
    {
        return $matches[0] ?? null;
    }

    private function resolveHistoricalRuleReuse(int $mailboxId, array $mailbox, array $message): ?array
    {
        $record = $this->messageState->findLatestThreadHandlingRecord($mailboxId, $message);
        if (!is_array($record)) {
            return null;
        }

        $matchMode = (string) ($record['thread_match_mode'] ?? 'explicit_links');

        $selectedRuleId = (int) (($record['selected_rule']['id'] ?? 0));
        if ($selectedRuleId > 0) {
            $rule = $this->findMailboxRuleById((array) ($mailbox['rules'] ?? []), $selectedRuleId);
            if (is_array($rule)) {
                return [
                    'type' => 'selected_rule',
                    'source' => $this->buildHistoricalRuleReuseSource('selected_rule', $matchMode),
                    'record' => $record,
                    'rule' => $rule,
                ];
            }
        }

        $noMatchRuleId = (int) (($record['generic_ai_decision']['matched_no_match_rule_id'] ?? 0));
        if ($noMatchRuleId > 0) {
            $preferredNoMatchRule = null;
            foreach ($this->resolveGenericNoMatchRules($mailbox) as $row) {
                if ((int) ($row['id'] ?? 0) === $noMatchRuleId) {
                    $preferredNoMatchRule = $row;
                    break;
                }
            }

            return [
                'type' => 'generic_no_match_rule',
                'source' => $this->buildHistoricalRuleReuseSource('generic_no_match_rule', $matchMode),
                'record' => $record,
                'preferred_no_match_rule_id' => $noMatchRuleId,
                'preferred_no_match_rule_order' => (int) (($record['generic_ai_decision']['matched_no_match_rule_order'] ?? 0)),
                'preferred_no_match_rule' => $preferredNoMatchRule,
            ];
        }

        return null;
    }

    private function findMailboxRuleById(array $rules, int $ruleId): ?array
    {
        foreach ($rules as $rule) {
            if ((int) ($rule['id'] ?? 0) === $ruleId) {
                return (array) $rule;
            }
        }

        return null;
    }

    private function buildHistoricalRuleReuseSource(string $type, string $matchMode): string
    {
        $type = strtolower(trim($type));
        $matchMode = strtolower(trim($matchMode));

        if ($type === 'generic_no_match_rule') {
            return $matchMode === 'subject_participants'
                ? 'thread_history_generic_no_match_subject_fallback'
                : 'thread_history_generic_no_match';
        }

        return $matchMode === 'subject_participants'
            ? 'thread_history_selected_rule_subject_fallback'
            : 'thread_history_selected_rule';
    }

    private function evaluateRuleMatch(array $messageFields, array $rule): array
    {
        $configuredFields = [
            'from_contains' => (string) (($rule['match']['from_contains'] ?? null) ?: ''),
            'to_contains' => (string) (($rule['match']['to_contains'] ?? null) ?: ''),
            'subject_contains' => (string) (($rule['match']['subject_contains'] ?? null) ?: ''),
            'body_contains' => (string) (($rule['match']['body_contains'] ?? null) ?: ''),
        ];

        $matchedFields = [];
        $criteriaCount = 0;
        $specificityLength = 0;
        $matchPriorityScore = 0;
        $fieldPriorityWeights = [
            'subject_contains' => 400,
            'body_contains' => 300,
            'to_contains' => 200,
            'from_contains' => 100,
        ];

        foreach ($configuredFields as $field => $needle) {
            $needle = trim($needle);
            if ($needle === '') {
                continue;
            }

            $criteriaCount++;
            $specificityLength += function_exists('mb_strlen') ? (int) mb_strlen($needle, 'UTF-8') : strlen($needle);
            $haystack = (string) ($messageFields[$field] ?? '');
            if (!$this->containsMatch($haystack, $needle)) {
                return [
                    'matched' => false,
                    'rule' => $rule,
                ];
            }

            $matchedFields[$field] = $needle;
            $matchPriorityScore += (int) ($fieldPriorityWeights[$field] ?? 0);
        }

        return [
            'matched' => true,
            'rule' => $rule,
            'matched_fields' => $matchedFields,
            'active_criteria_count' => $criteriaCount,
            'specificity_length' => $specificityLength,
            'match_priority_score' => $matchPriorityScore,
        ];
    }

    private function buildRuleMatchSummary(array $match): array
    {
        $rule = (array) ($match['rule'] ?? []);

        return [
            'id' => (int) ($rule['id'] ?? 0),
            'name' => (string) ($rule['name'] ?? ''),
            'sort_order' => (int) ($rule['sort_order'] ?? 0),
            'match_priority_score' => (int) ($match['match_priority_score'] ?? 0),
            'active_criteria_count' => (int) ($match['active_criteria_count'] ?? 0),
            'specificity_length' => (int) ($match['specificity_length'] ?? 0),
            'matched_fields' => (array) ($match['matched_fields'] ?? []),
        ];
    }

    private function containsMatch(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return true;
        }

        return stripos($haystack, $needle) !== false;
    }

    protected function createImapMailboxClient(array $config): ImapMailboxClient
    {
        return new ImapMailboxClient($config);
    }

    private function shouldMarkSeenOnSkip(array $mailbox, string $reason): bool
    {
        if (empty($mailbox['defaults']['mark_seen_on_skip'])) {
            return false;
        }

        $reason = strtolower(trim($reason));
        if ($reason === '') {
            return false;
        }

        if (strpos($reason, 'no_matching_rule') === 0) {
            return false;
        }

        return true;
    }

    private function tryHandleGenericNoMatch(ImapMailboxClient $imap, array $mailbox, array $message, bool $dryRun, ?array $threadReuse = null): array
    {
        if (!$this->isGenericNoMatchAiEnabled($mailbox)) {
            return [
                'handled' => false,
                'reason' => 'no_matching_rule_generic_ai_disabled',
                'ai_decision' => [],
                'rule_resolution_source' => (string) (($threadReuse['source'] ?? null) ?: 'generic_no_match_disabled'),
                'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
                'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
            ];
        }

        $defaults = (array) ($mailbox['defaults'] ?? []);
        $noMatchRules = $this->prioritizeGenericNoMatchRules($this->resolveGenericNoMatchRules($mailbox), $threadReuse);
        if (!$noMatchRules) {
            $this->logger->info('Generic no-match AI fallback skipped: IF condition is missing.', [
                'mailbox' => $mailbox['name'] ?? null,
                'uid' => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
            ]);

            return [
                'handled' => false,
                'reason' => 'no_matching_rule_generic_ai_unconfigured',
                'ai_decision' => [],
                'rule_resolution_source' => (string) (($threadReuse['source'] ?? null) ?: 'generic_no_match_unconfigured'),
                'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
                'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
            ];
        }

        if ($this->shouldBypassGenericNoMatchAllowCheck($threadReuse)) {
            $preferredRule = is_array($threadReuse['preferred_no_match_rule'] ?? null)
                ? (array) $threadReuse['preferred_no_match_rule']
                : null;

            if (is_array($preferredRule)) {
                $directContinuation = $this->tryContinueKnownGenericNoMatchThread(
                    $imap,
                    $mailbox,
                    $message,
                    $preferredRule,
                    $defaults,
                    $dryRun,
                    $threadReuse
                );
                if (is_array($directContinuation)) {
                    return $directContinuation;
                }
            }
        }

        $lastDecision = [];
        $evaluatedRules = [];

        try {
            foreach ($noMatchRules as $noMatchRule) {
                try {
                    $aiResult = $this->tools->evaluateGenericNoMatchReply($mailbox, $message, [
                        'if_condition' => (string) ($noMatchRule['if_condition'] ?? ''),
                        'reply_instruction' => (string) ($noMatchRule['instruction'] ?? ''),
                        'ai_model' => (string) ($noMatchRule['ai_model'] ?? (($defaults['generic_no_match_ai_model'] ?? null) ?: '')),
                        'ai_fallback_model' => (string) (($defaults['generic_no_match_ai_fallback_model'] ?? null) ?: ''),
                        'ai_reasoning_effort' => (string) ($noMatchRule['ai_reasoning_effort'] ?? (($defaults['generic_no_match_ai_reasoning_effort'] ?? null) ?: '')),
                        'source' => (string) ($noMatchRule['source'] ?? 'advanced_row_rule'),
                        'no_match_rule_id' => (int) ($noMatchRule['id'] ?? 0),
                    ]);
                    $replyText = trim((string) ($aiResult['reply'] ?? ($aiResult['response'] ?? '')));
                    $decision = [
                        'can_reply' => !empty($aiResult['can_reply']),
                        'certainty' => (string) ($aiResult['certainty'] ?? ''),
                        'reason' => (string) ($aiResult['reason'] ?? ''),
                        'risk_flags' => (array) ($aiResult['risk_flags'] ?? []),
                        'decision_reason_code' => (string) ($aiResult['decision_reason_code'] ?? ''),
                        'raw_response' => (string) ($aiResult['raw_response'] ?? ''),
                        'matched_no_match_rule_id' => (int) ($noMatchRule['id'] ?? 0),
                        'matched_no_match_rule_order' => (int) ($noMatchRule['sort_order'] ?? 0),
                    ];
                } catch (Throwable $rowError) {
                    $decision = [
                        'can_reply' => false,
                        'certainty' => 'low',
                        'reason' => $rowError->getMessage(),
                        'risk_flags' => ['evaluation_error'],
                        'decision_reason_code' => 'no_matching_rule_generic_ai_error',
                        'raw_response' => '',
                        'matched_no_match_rule_id' => (int) ($noMatchRule['id'] ?? 0),
                        'matched_no_match_rule_order' => (int) ($noMatchRule['sort_order'] ?? 0),
                    ];
                    $evaluatedRules[] = $this->buildGenericNoMatchRuleDecisionSummary($noMatchRule, $decision);
                    $lastDecision = $decision;

                    $this->logger->warning('Generic no-match AI row evaluation failed; later active rows will still be tried.', [
                        'mailbox' => $mailbox['name'] ?? null,
                        'uid' => $message['uid'] ?? null,
                        'message_id' => $message['message_id'] ?? null,
                        'no_match_rule_id' => $noMatchRule['id'] ?? null,
                        'no_match_rule_order' => $noMatchRule['sort_order'] ?? null,
                        'error' => $rowError->getMessage(),
                    ]);
                    continue;
                }

                $evaluatedRules[] = $this->buildGenericNoMatchRuleDecisionSummary($noMatchRule, $decision);
                $lastDecision = $decision;

                if (empty($aiResult['can_reply'])) {
                    $this->logger->info('Generic no-match AI row rejected this message; later active rows will still be tried if available.', [
                        'mailbox' => $mailbox['name'] ?? null,
                        'uid' => $message['uid'] ?? null,
                        'message_id' => $message['message_id'] ?? null,
                        'no_match_rule_id' => $noMatchRule['id'] ?? null,
                        'no_match_rule_order' => $noMatchRule['sort_order'] ?? null,
                        'decision_reason_code' => $decision['decision_reason_code'] ?? null,
                        'decision_reason' => $decision['reason'] ?? null,
                    ]);
                    continue;
                }

                if (!$this->isGenericAiReplyUsable($replyText)) {
                    $lastDecision['decision_reason_code'] = (string) ($lastDecision['decision_reason_code'] ?: 'no_matching_rule_generic_ai_empty_reply');
                    if (trim((string) ($lastDecision['reason'] ?? '')) === '') {
                        $lastDecision['reason'] = 'Generic no-match AI allowed a reply but did not return usable reply text.';
                    }
                    $evaluatedRules[count($evaluatedRules) - 1] = $this->buildGenericNoMatchRuleDecisionSummary($noMatchRule, $lastDecision);
                    $this->logger->info('Generic no-match AI fallback skipped: response not usable.', [
                        'mailbox' => $mailbox['name'] ?? null,
                        'uid' => $message['uid'] ?? null,
                        'message_id' => $message['message_id'] ?? null,
                        'no_match_rule_id' => $noMatchRule['id'] ?? null,
                    ]);
                    continue;
                }

                $replyText = $this->applyGenericFallbackFooter($mailbox, $replyText, (string) ($noMatchRule['footer'] ?? ''));
                $subjectPrefix = trim((string) (($defaults['generic_no_match_subject_prefix'] ?? null) ?: 'Re:'));
                $subject = $this->buildReplySubject((string) ($message['subject'] ?? ''), $subjectPrefix);
                $syntheticRule = [
                    'reply' => [
                        'from_name' => (string) (($defaults['from_name'] ?? null) ?: ''),
                        'from_email' => (string) (($defaults['from_email'] ?? null) ?: ''),
                        'bcc' => (string) (($defaults['bcc'] ?? null) ?: ''),
                    ],
                ];

                $replyDelivery = $this->sendReply($mailbox, $syntheticRule, $message, $subject, $replyText, $dryRun);
                $finalizeResult = $this->finalizeHandledMessage($imap, $message, $dryRun);

                $this->logger->info('Generic no-match AI fallback reply sent.', [
                    'mailbox' => $mailbox['name'] ?? null,
                    'uid' => $message['uid'] ?? null,
                    'message_id' => $message['message_id'] ?? null,
                    'dry_run' => $dryRun,
                    'used_fallback_model' => !empty($aiResult['used_fallback_model']),
                    'model' => $aiResult['model'] ?? null,
                    'certainty' => $aiResult['certainty'] ?? null,
                    'decision_reason' => $aiResult['reason'] ?? null,
                    'risk_flags' => $aiResult['risk_flags'] ?? [],
                    'matched_no_match_rule_id' => $noMatchRule['id'] ?? null,
                    'matched_no_match_rule_order' => $noMatchRule['sort_order'] ?? null,
                    'post_handle_action' => $finalizeResult['post_handle_action'] ?? null,
                    'post_handle_warning' => $finalizeResult['post_handle_warning'] ?? null,
                    'evaluated_no_match_rules' => $evaluatedRules,
                ]);

                return [
                    'handled' => true,
                    'reason' => !empty($finalizeResult['post_handle_warning'])
                        ? 'no_matching_rule_generic_ai_replied_imap_finalize_failed'
                        : 'no_matching_rule_generic_ai_replied',
                    'post_handle_warning' => (string) ($finalizeResult['post_handle_warning'] ?? ''),
                    'post_handle_action' => (string) ($finalizeResult['post_handle_action'] ?? ''),
                    'ai_decision' => $this->attachGenericNoMatchEvaluationTrace($decision, $evaluatedRules),
                    'reply_excerpt' => $this->buildStateExcerpt($replyText, 500),
                    'reply_message_id' => (string) ($replyDelivery['reply_message_id'] ?? ''),
                    'reply_transport' => (string) ($replyDelivery['transport'] ?? ''),
                    'rule_resolution_source' => (string) (($threadReuse['source'] ?? null) ?: 'generic_no_match'),
                    'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
                    'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
                ];
            }

            return [
                'handled' => false,
                'reason' => (string) ($lastDecision['decision_reason_code'] ?? 'no_matching_rule_generic_ai_rejected'),
                'ai_decision' => $this->attachGenericNoMatchEvaluationTrace($lastDecision, $evaluatedRules),
                'rule_resolution_source' => (string) (($threadReuse['source'] ?? null) ?: 'generic_no_match'),
                'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
                'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
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
                'ai_decision' => $this->attachGenericNoMatchEvaluationTrace([
                    'reason' => $e->getMessage(),
                ], $evaluatedRules),
                'rule_resolution_source' => (string) (($threadReuse['source'] ?? null) ?: 'generic_no_match'),
                'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
                'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
            ];
        }
    }

    private function prioritizeGenericNoMatchRules(array $noMatchRules, ?array $threadReuse = null): array
    {
        $preferredRuleId = (int) (($threadReuse['preferred_no_match_rule_id'] ?? 0));
        if ($preferredRuleId < 1) {
            return $noMatchRules;
        }

        $preferred = [];
        $others = [];
        foreach ($noMatchRules as $row) {
            if ((int) ($row['id'] ?? 0) === $preferredRuleId) {
                $preferred[] = $row;
                continue;
            }

            $others[] = $row;
        }

        if (!count($preferred)) {
            return $noMatchRules;
        }

        $this->logger->info('Prioritizing the previously used generic no-match row for this reply chain before evaluating other active rows.', [
            'reused_from_message_id' => $threadReuse['record']['message_id'] ?? null,
            'reused_from_reason' => $threadReuse['record']['reason'] ?? null,
            'preferred_no_match_rule_id' => $preferredRuleId,
        ]);

        return array_merge($preferred, $others);
    }

    private function shouldBypassGenericNoMatchAllowCheck(?array $threadReuse): bool
    {
        if (!is_array($threadReuse)) {
            return false;
        }

        if ((string) ($threadReuse['type'] ?? '') !== 'generic_no_match_rule') {
            return false;
        }

        return strtolower(trim((string) (($threadReuse['record']['thread_match_mode'] ?? null) ?: ''))) === 'explicit_links';
    }

    private function tryContinueKnownGenericNoMatchThread(
        ImapMailboxClient $imap,
        array $mailbox,
        array $message,
        array $noMatchRule,
        array $defaults,
        bool $dryRun,
        array $threadReuse
    ): ?array {
        try {
            $aiResult = $this->tools->generateGenericNoMatchThreadContinuationReply($mailbox, $message, [
                'if_condition' => (string) ($noMatchRule['if_condition'] ?? ''),
                'reply_instruction' => (string) ($noMatchRule['instruction'] ?? ''),
                'ai_model' => (string) ($noMatchRule['ai_model'] ?? (($defaults['generic_no_match_ai_model'] ?? null) ?: '')),
                'ai_fallback_model' => (string) (($defaults['generic_no_match_ai_fallback_model'] ?? null) ?: ''),
                'ai_reasoning_effort' => (string) ($noMatchRule['ai_reasoning_effort'] ?? (($defaults['generic_no_match_ai_reasoning_effort'] ?? null) ?: '')),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Known reply-chain generic no-match row could not be continued directly; falling back to normal unmatched-row evaluation.', [
                'mailbox' => $mailbox['name'] ?? null,
                'uid' => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'reused_from_message_id' => $threadReuse['record']['message_id'] ?? null,
                'preferred_no_match_rule_id' => $noMatchRule['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $replyText = trim((string) ($aiResult['response'] ?? ''));
        if (!$this->isGenericAiReplyUsable($replyText)) {
            $this->logger->warning('Known reply-chain generic no-match row returned no usable continuation reply; falling back to normal unmatched-row evaluation.', [
                'mailbox' => $mailbox['name'] ?? null,
                'uid' => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'reused_from_message_id' => $threadReuse['record']['message_id'] ?? null,
                'preferred_no_match_rule_id' => $noMatchRule['id'] ?? null,
                'model' => $aiResult['model'] ?? null,
            ]);

            return null;
        }

        $replyText = $this->applyGenericFallbackFooter($mailbox, $replyText, (string) ($noMatchRule['footer'] ?? ''));
        $subjectPrefix = trim((string) (($defaults['generic_no_match_subject_prefix'] ?? null) ?: 'Re:'));
        $subject = $this->buildReplySubject((string) ($message['subject'] ?? ''), $subjectPrefix);
        $syntheticRule = [
            'reply' => [
                'from_name' => (string) (($defaults['from_name'] ?? null) ?: ''),
                'from_email' => (string) (($defaults['from_email'] ?? null) ?: ''),
                'bcc' => (string) (($defaults['bcc'] ?? null) ?: ''),
            ],
        ];

        $replyDelivery = $this->sendReply($mailbox, $syntheticRule, $message, $subject, $replyText, $dryRun);
        $finalizeResult = $this->finalizeHandledMessage($imap, $message, $dryRun);
        $decision = [
            'can_reply' => true,
            'certainty' => 'high',
            'reason' => 'Continuing an already approved reply chain through the previously used unmatched support row.',
            'risk_flags' => ['known_reply_chain_reuse'],
            'decision_reason_code' => 'thread_history_generic_no_match_reused',
            'raw_response' => (string) ($aiResult['response'] ?? ''),
            'matched_no_match_rule_id' => (int) ($noMatchRule['id'] ?? 0),
            'matched_no_match_rule_order' => (int) ($noMatchRule['sort_order'] ?? 0),
            'bypassed_allow_check' => true,
        ];
        $evaluatedRules = [$this->buildGenericNoMatchRuleDecisionSummary($noMatchRule, $decision)];

        $this->logger->info('Known reply-chain generic no-match row reused directly without re-running the initial allow-condition classifier.', [
            'mailbox' => $mailbox['name'] ?? null,
            'uid' => $message['uid'] ?? null,
            'message_id' => $message['message_id'] ?? null,
            'reused_from_message_id' => $threadReuse['record']['message_id'] ?? null,
            'matched_no_match_rule_id' => $noMatchRule['id'] ?? null,
            'matched_no_match_rule_order' => $noMatchRule['sort_order'] ?? null,
            'post_handle_action' => $finalizeResult['post_handle_action'] ?? null,
            'post_handle_warning' => $finalizeResult['post_handle_warning'] ?? null,
        ]);

        return [
            'handled' => true,
            'reason' => !empty($finalizeResult['post_handle_warning'])
                ? 'no_matching_rule_generic_ai_replied_imap_finalize_failed'
                : 'no_matching_rule_generic_ai_replied',
            'post_handle_warning' => (string) ($finalizeResult['post_handle_warning'] ?? ''),
            'post_handle_action' => (string) ($finalizeResult['post_handle_action'] ?? ''),
            'ai_decision' => $this->attachGenericNoMatchEvaluationTrace($decision, $evaluatedRules),
            'reply_excerpt' => $this->buildStateExcerpt($replyText, 500),
            'reply_message_id' => (string) ($replyDelivery['reply_message_id'] ?? ''),
            'reply_transport' => (string) ($replyDelivery['transport'] ?? ''),
            'rule_resolution_source' => (string) (($threadReuse['source'] ?? null) ?: 'generic_no_match'),
            'reused_from_message_id' => (string) (($threadReuse['record']['message_id'] ?? null) ?: ''),
            'reused_from_reason' => (string) (($threadReuse['record']['reason'] ?? null) ?: ''),
        ];
    }

    private function buildGenericNoMatchRuleDecisionSummary(array $noMatchRule, array $decision): array
    {
        $source = (string) (($noMatchRule['source'] ?? null) ?: 'advanced_row_rule');

        return [
            'id' => (int) ($noMatchRule['id'] ?? 0),
            'sort_order' => (int) ($noMatchRule['sort_order'] ?? 0),
            'source' => $source,
            'is_mailbox_final_fallback' => $source === 'mailbox_final_fallback',
            'if_condition' => (string) ($noMatchRule['if_condition'] ?? ''),
            'decision_reason_code' => (string) ($decision['decision_reason_code'] ?? ''),
            'can_reply' => !empty($decision['can_reply']),
            'certainty' => (string) ($decision['certainty'] ?? ''),
            'reason' => (string) ($decision['reason'] ?? ''),
            'risk_flags' => array_values((array) ($decision['risk_flags'] ?? [])),
        ];
    }

    private function attachGenericNoMatchEvaluationTrace(array $decision, array $evaluatedRules): array
    {
        $decision['evaluated_no_match_rules'] = array_values($evaluatedRules);

        return $decision;
    }

    private function isGenericNoMatchAiEnabled(array $mailbox): bool
    {
        $defaults = (array) ($mailbox['defaults'] ?? []);

        return $this->toNullableBool($defaults['generic_no_match_ai_enabled'] ?? null) === true;
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

    private function applyGenericFallbackFooter(array $mailbox, string $text, string $ruleFooter = ''): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $defaults = (array) ($mailbox['defaults'] ?? []);
        $footer = trim($ruleFooter);
        if ($footer === '') {
            $footer = trim((string) (($defaults['generic_no_match_footer'] ?? null) ?: (($defaults['footer'] ?? null) ?: '')));
        }
        if ($footer === '') {
            return $text;
        }

        $text = $this->stripTrailingGeneratedSignoff($text);

        return rtrim($text) . "\n\n" . $footer;
    }

    private function resolveGenericNoMatchRules(array $mailbox): array
    {
        if (!$this->isGenericNoMatchAiEnabled($mailbox)) {
            return [];
        }

        $defaults = (array) ($mailbox['defaults'] ?? []);
        $rows = [];
        foreach ((array) ($defaults['generic_no_match_rules'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $isActive = $this->toNullableBool($row['is_active'] ?? true);
            if ($isActive === false) {
                continue;
            }

            $ifCondition = trim((string) (($row['if'] ?? null) ?: ($row['if_condition'] ?? null) ?: ''));
            $instruction = trim((string) (($row['instruction'] ?? null) ?: ''));
            if ($ifCondition === '' || $instruction === '') {
                continue;
            }

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'if_condition' => $ifCondition,
                'instruction' => $instruction,
                'footer' => trim((string) (($row['footer'] ?? null) ?: '')),
                'ai_model' => trim((string) (($row['ai_model'] ?? null) ?: '')),
                'ai_reasoning_effort' => trim((string) (($row['ai_reasoning_effort'] ?? null) ?: '')),
                'source' => 'advanced_row_rule',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $orderCompare = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);
            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $finalFallbackIf = trim((string) (($defaults['generic_no_match_if'] ?? null) ?: ''));
        $finalFallbackInstruction = trim((string) (($defaults['generic_no_match_instruction'] ?? null) ?: ''));
        if ($finalFallbackIf !== '' && $finalFallbackInstruction !== '') {
            $lastSortOrder = count($rows)
                ? max(array_map(static function (array $row): int {
                    return (int) ($row['sort_order'] ?? 0);
                }, $rows))
                : 0;

            $rows[] = [
                'id' => 900000000 + (int) ($mailbox['id'] ?? 0),
                'sort_order' => $lastSortOrder + 1,
                'if_condition' => $finalFallbackIf,
                'instruction' => $finalFallbackInstruction,
                'footer' => trim((string) (($defaults['generic_no_match_footer'] ?? null) ?: '')),
                'ai_model' => trim((string) (($defaults['generic_no_match_ai_model'] ?? null) ?: '')),
                'ai_reasoning_effort' => trim((string) (($defaults['generic_no_match_ai_reasoning_effort'] ?? null) ?: '')),
                'source' => 'mailbox_final_fallback',
            ];
        }

        return $rows;
    }

    private function handleMessage(ImapMailboxClient $imap, array $mailbox, array $rule, array $message, bool $dryRun): array
    {
        $replyConfig = (array) ($rule['reply'] ?? []);
        $replySent = false;
        $replyDelivery = [];
        if (!empty($replyConfig['enabled'])) {
            $replyText = $this->buildReplyText($mailbox, $rule, $message);
            $subject = $this->buildReplySubject((string) ($message['subject'] ?? ''), (string) (($replyConfig['subject_prefix'] ?? null) ?: 'Re:'));
            $replyDelivery = $this->sendReply($mailbox, $rule, $message, $subject, $replyText, $dryRun);
            $replySent = true;
        }

        if (!$replySent && !$this->shouldFinalizeWithoutReply($mailbox, $rule)) {
            return [
                'handled' => false,
                'reason' => 'rule_matched_reply_not_sent',
                'post_handle_action' => 'none',
                'post_handle_warning' => '',
                'reply_sent' => false,
            ];
        }

        $postHandle = (array) ($rule['post_handle'] ?? []);
        $finalizeResult = $this->finalizeHandledMessage($imap, $message, $dryRun, $postHandle);
        $finalizeResult['reply_sent'] = $replySent;
        $finalizeResult['handled'] = true;
        $finalizeResult['reason'] = $replySent
            ? 'rule_matched_replied'
            : 'rule_matched_processed_without_reply';
        if ($replySent) {
            $finalizeResult['reply_excerpt'] = $this->buildStateExcerpt($replyText, 500);
            $finalizeResult['reply_message_id'] = (string) ($replyDelivery['reply_message_id'] ?? '');
            $finalizeResult['reply_transport'] = (string) ($replyDelivery['transport'] ?? '');
        }

        return $finalizeResult;
    }

    private function shouldFinalizeWithoutReply(array $mailbox, array $rule): bool
    {
        $postHandle = (array) ($rule['post_handle'] ?? []);
        $defaults = (array) ($mailbox['defaults'] ?? []);

        $candidates = [
            $postHandle['apply_without_reply'] ?? null,
            $postHandle['allow_without_reply'] ?? null,
            $postHandle['finalize_without_reply'] ?? null,
            $defaults['apply_without_reply'] ?? null,
            $defaults['allow_without_reply'] ?? null,
            $defaults['finalize_without_reply'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $parsed = $this->toNullableBool($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return Env::bool('MAIL_ASSISTANT_FINALIZE_WITHOUT_REPLY', false);
    }

    private function finalizeHandledMessage(ImapMailboxClient $imap, array $message, bool $dryRun, array $postHandle = []): array
    {
        $uid = (int) ($message['uid'] ?? 0);
        if ($uid < 1) {
            return [
                'post_handle_action' => 'none',
                'post_handle_warning' => '',
            ];
        }

        if ($dryRun) {
            return [
                'post_handle_action' => 'dry_run',
                'post_handle_warning' => '',
            ];
        }

        if (!empty($postHandle['delete_after_handle'])) {
            if ($imap->deleteMessage($uid)) {
                return [
                    'post_handle_action' => 'delete',
                    'post_handle_warning' => '',
                ];
            }

            return [
                'post_handle_action' => 'delete',
                'post_handle_warning' => 'Reply was sent, but deleting the handled message from IMAP failed; the message may remain unread until it is cleaned up manually.',
            ];
        }

        $moveTo = trim((string) (($postHandle['move_to_folder'] ?? null) ?: ''));
        if ($moveTo !== '') {
            if ($imap->moveMessage($uid, $moveTo)) {
                return [
                    'post_handle_action' => 'move:' . $moveTo,
                    'post_handle_warning' => '',
                ];
            }

            return [
                'post_handle_action' => 'move:' . $moveTo,
                'post_handle_warning' => 'Reply was sent, but moving the handled message to "' . $moveTo . '" failed; the message may remain unread in the inbox until it is moved manually.',
            ];
        }

        if ($imap->markSeen($uid)) {
            return [
                'post_handle_action' => 'mark_seen',
                'post_handle_warning' => '',
            ];
        }

        return [
            'post_handle_action' => 'mark_seen',
            'post_handle_warning' => 'Reply was sent, but IMAP could not mark the message as seen; it may stay unread and would otherwise be retried on a later run.',
        ];
    }

    private function buildReplyText(array $mailbox, array $rule, array $message): string
    {
        $replyConfig = (array) ($rule['reply'] ?? []);
        $text = '';
        $template = trim((string) (($replyConfig['template_text'] ?? null) ?: ''));
        $lastAiError = '';
        $lastAiModelTrail = $this->describeConfiguredAiModelTrail($replyConfig);

        if (!empty($replyConfig['ai_enabled'])) {
            try {
                $aiResult = $this->tools->generateAiReply($mailbox, $rule, $message);
                $text = trim((string) ($aiResult['response'] ?? ''));
                $lastAiModelTrail = $this->describeAiModelTrailFromResult($aiResult, $lastAiModelTrail);
                $instructionViolation = $this->validateAiReplyAgainstInstruction($text, $mailbox, $rule, $message);
                if ($instructionViolation !== null) {
                    $lastAiError = $instructionViolation;
                    $text = '';
                    $this->logger->warning('AI reply failed instruction-compliance validation and will not be sent.', [
                        'reason' => $instructionViolation,
                        'subject' => $message['subject'] ?? null,
                        'from' => $message['from'] ?? null,
                    ]);
                }
            } catch (Throwable $e) {
                $lastAiError = trim($e->getMessage());
                $this->logger->warning('AI reply generation failed, falling back to template.', ['error' => $e->getMessage()]);
            }
        }

        if ($text === '') {
            if (!empty($replyConfig['ai_enabled']) && $template === '') {
                $reason = $lastAiError !== ''
                    ? 'AI reply generation failed: ' . $lastAiError
                    : 'AI reply generation returned an empty response.';
                if ($lastAiModelTrail !== '') {
                    $reason .= ' Models used: ' . $lastAiModelTrail . '.';
                }

                throw new RuntimeException($reason . ' No explicit fallback template is configured for this AI-enabled rule.');
            }

            $template = $template !== '' ? $template : 'Thank you for your message. We have reviewed it.';
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
                $text = $this->stripTrailingGeneratedSignoff($text);
                $text = rtrim($text) . "\n\n" . $footer;
            }
        }

        return trim($text);
    }

    private function stripTrailingGeneratedSignoff(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [];
        $paragraphs = array_values(array_filter(array_map(static function (string $paragraph): string {
            return trim($paragraph);
        }, $paragraphs), static function (string $paragraph): bool {
            return $paragraph !== '';
        }));

        if (!count($paragraphs)) {
            return $text;
        }

        $isSignoffParagraph = static function (string $paragraph): bool {
            $normalized = preg_replace('/\s+/u', ' ', trim($paragraph)) ?? trim($paragraph);
            if ($normalized === '') {
                return false;
            }

            if (preg_match('/^(kind regards|regards|best regards|sincerely|thanks|thank you|med vänlig hälsning|vänliga hälsningar|hälsningar|vänligen|mvh|tack)[,!.\s]*$/iu', $normalized) === 1) {
                return true;
            }

            return preg_match('/^(kind regards|regards|best regards|sincerely|med vänlig hälsning|vänliga hälsningar|hälsningar|vänligen|mvh)[,!.\s]+.+$/iu', $normalized) === 1;
        };

        while (count($paragraphs)) {
            $removed = false;
            $lastIndex = count($paragraphs) - 1;
            if ($isSignoffParagraph($paragraphs[$lastIndex])) {
                array_pop($paragraphs);
                $removed = true;
            } elseif (count($paragraphs) >= 2) {
                $candidate = $paragraphs[$lastIndex - 1] . "\n" . $paragraphs[$lastIndex];
                if ($isSignoffParagraph($candidate)) {
                    array_pop($paragraphs);
                    array_pop($paragraphs);
                    $removed = true;
                }
            }

            if (!$removed) {
                break;
            }
        }

        return trim(implode("\n\n", $paragraphs));
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

    private function evaluateReplySpamScoreThreshold(array $mailbox, array $message): array
    {
        $threshold = $this->resolveReplySpamScoreThreshold($mailbox);
        if ($threshold === null) {
            return [
                'skip_reply' => false,
                'reason' => '',
                'threshold' => null,
                'score' => null,
            ];
        }

        $spam = is_array($message['spam_assassin'] ?? null) ? $message['spam_assassin'] : [];
        $score = isset($spam['score']) && is_numeric($spam['score']) ? (float) $spam['score'] : null;
        if ($score === null) {
            return [
                'skip_reply' => false,
                'reason' => '',
                'threshold' => $threshold,
                'score' => null,
            ];
        }

        $skipReply = $score > $threshold;

        return [
            'skip_reply' => $skipReply,
            'reason' => $skipReply ? 'spam_score_reply_threshold_exceeded' : '',
            'threshold' => $threshold,
            'score' => $score,
        ];
    }

    private function resolveReplySpamScoreThreshold(array $mailbox): ?float
    {
        $defaults = (array) ($mailbox['defaults'] ?? []);
        $value = $defaults['spam_score_reply_threshold'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $threshold = (float) $value;
        if ($threshold <= 0) {
            return null;
        }

        return $threshold;
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

    private function recordMessageState(array &$mailboxSummary, array $message, string $status, string $reason, bool $dryRun, array $extra = []): void
    {
        $messageKey = $this->resolveMessageKey($message);
        if ($messageKey === '') {
            return;
        }

        $mailboxId = (int) ($mailboxSummary['id'] ?? 0);
        $record = [
            'message_id' => (string) ($message['message_id'] ?? ''),
            'thread_key' => $this->resolveThreadKey($message),
            'subject_normalized' => (string) (($message['subject_normalized'] ?? null) ?: ''),
            'in_reply_to' => (string) (($message['in_reply_to'] ?? null) ?: ''),
            'references' => array_values((array) ($message['references'] ?? [])),
            'status' => $status,
            'reason' => $reason,
            'subject' => (string) ($message['subject'] ?? ''),
            'from' => (string) ($message['from'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
            'date' => (string) ($message['date'] ?? ''),
            'uid' => (int) ($message['uid'] ?? 0),
            'dry_run' => $dryRun,
            'body_excerpt' => $this->buildStateMessageBodyExcerpt($message),
        ];
        foreach ($extra as $key => $value) {
            $record[(string) $key] = $value;
        }

        if (!$dryRun) {
            $this->messageState->remember($mailboxId, $messageKey, $record);
        }

        if ($this->includeHistory) {
            $mailboxSummary['message_state_records'][] = array_merge(['message_key' => $messageKey], $record);
        }
    }

    private function preserveUnreadState(ImapMailboxClient $imap, array $message, bool $dryRun, string $reason): void
    {
        if ($dryRun) {
            return;
        }

        $uid = (int) ($message['uid'] ?? 0);
        if ($uid < 1) {
            return;
        }

        $result = $imap->markUnseen($uid);
        if ($result) {
            $this->logger->info('Message explicitly kept unread after skip/non-reply decision.', [
                'uid' => $uid,
                'message_id' => $message['message_id'] ?? null,
                'reason' => $reason,
            ]);
            return;
        }

        $this->logger->warning('Mailbox client could not explicitly clear the seen flag after skip/non-reply decision.', [
            'uid' => $uid,
            'message_id' => $message['message_id'] ?? null,
            'reason' => $reason,
        ]);
    }

    private function buildStateMessageBodyExcerpt(array $message): string
    {
        $candidates = [
            (string) (($message['body_text_reply_aware'] ?? null) ?: ''),
            (string) (($message['body_text'] ?? null) ?: ''),
            (string) (($message['body_text_raw'] ?? null) ?: ''),
        ];

        foreach ($candidates as $candidate) {
            $excerpt = $this->buildStateExcerpt($candidate, 500);
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return '';
    }

    private function buildStateExcerpt(string $text, int $maxLength = 500): string
    {
        return MimeDecoder::extractRequestSummaryText($text, $maxLength);
    }

    private function resolveThreadKey(array $message): string
    {
        $references = array_values(array_filter((array) ($message['references'] ?? []), static function ($value): bool {
            return trim((string) $value) !== '';
        }));
        if (count($references)) {
            return strtolower(trim((string) $references[0]));
        }

        $inReplyTo = strtolower(trim((string) (($message['in_reply_to'] ?? null) ?: '')));
        if ($inReplyTo !== '') {
            return $inReplyTo;
        }

        $subjectNormalized = strtolower(trim((string) (($message['subject_normalized'] ?? null) ?: '')));
        if ($subjectNormalized !== '') {
            return 'subject:' . $subjectNormalized;
        }

        return strtolower(trim((string) (($message['message_id'] ?? null) ?: '')));
    }

    private function recordMessageResult(array &$mailboxSummary, array $message, string $outcome, string $reason, array $extra = []): void
    {
        $result = [
            'uid' => (int) ($message['uid'] ?? 0),
            'message_id' => (string) ($message['message_id'] ?? ''),
            'message_key' => $this->resolveMessageKey($message),
            'thread_key' => $this->resolveThreadKey($message),
            'in_reply_to' => (string) (($message['in_reply_to'] ?? null) ?: ''),
            'references' => array_values((array) ($message['references'] ?? [])),
            'subject' => (string) ($message['subject'] ?? ''),
            'subject_normalized' => (string) (($message['subject_normalized'] ?? null) ?: ''),
            'from' => (string) ($message['from'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
            'date' => (string) ($message['date'] ?? ''),
            'body_excerpt' => $this->buildStateMessageBodyExcerpt($message),
            'outcome' => $outcome,
            'reason' => $reason,
        ];

        foreach ($extra as $key => $value) {
            $result[(string) $key] = $value;
        }

        $mailboxSummary['message_results'][] = $result;
    }

    private function buildReplySubject(string $subject, string $prefix): string
    {
        $prefix = trim($prefix) !== '' ? trim($prefix) : 'Re:';
        if (stripos($subject, $prefix) === 0) {
            return $subject;
        }

        return $prefix . ' ' . trim($subject);
    }

    private function normalizeSubjectLine(string $subject, array $mailbox, array $rule = []): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return $subject;
        }

        // Resolve trim prefixes: rule-level overrides mailbox-level
        $ruleTrimPrefixes = (array) ($rule['subject_trim_prefixes'] ?? []);
        $mailboxTrimPrefixes = (array) ($mailbox['defaults']['subject_trim_prefixes'] ?? []);
        $trimPrefixes = !empty($ruleTrimPrefixes) ? $ruleTrimPrefixes : $mailboxTrimPrefixes;

        if (!count($trimPrefixes)) {
            return $subject;
        }

        // Remove prefixes from the beginning of subject (case-insensitive)
        $result = $subject;
        $lastLen = strlen($result) + 1;
        while ($lastLen !== strlen($result)) {
            $lastLen = strlen($result);
            foreach ($trimPrefixes as $prefix) {
                $trimmed = trim((string) $prefix);
                if ($trimmed === '') {
                    continue;
                }
                if (stripos($result, $trimmed) === 0) {
                    $result = ltrim(substr($result, strlen($trimmed)));
                    break;
                }
            }
        }

        return trim($result) !== '' ? trim($result) : $subject;
    }

    private function sendReply(array $mailbox, array $rule, array $message, string $subject, string $body, bool $dryRun): array
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

        $replyContent = $this->buildReplyContent($body, $message, $rule);
        $replyMessageId = $this->buildOutgoingReplyMessageId($fromEmail, $message);
        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            self::ASSISTANT_SENT_HEADER . ': ' . self::ASSISTANT_SENT_HEADER_VALUE,
        ];
        if ($replyMessageId !== '') {
            $headers[] = 'Message-ID: <' . trim($replyMessageId, "<> \t\n\r\0\x0B") . '>';
        }

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

        $bcc = trim((string) (($replyConfig['bcc'] ?? null) ?: (($mailbox['defaults']['bcc'] ?? null) ?: Env::get('MAIL_ASSISTANT_DEFAULT_BCC', ''))));
        if ($bcc !== '') {
            $headers[] = 'Bcc: ' . $bcc;
        }

        $resolvedRecipients = $this->resolveReplyRecipients($to, $headers);

        if ($dryRun) {
            $this->logger->info('DRY-RUN reply prepared.', [
                'to' => $to,
                'subject' => $subject,
                'cc' => $resolvedRecipients['cc'],
                'bcc' => $resolvedRecipients['bcc'],
                'has_html' => !empty($replyContent['html']),
                'reply_message_id' => $replyMessageId,
            ]);
            return [
                'reply_message_id' => $replyMessageId,
                'transport' => 'dry_run',
            ];
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
                $this->deliverReplyViaTransport($transport, $mailbox, $rule, $message, $to, $subject, $replyContent, $headers, $resolvedRecipients, $attemptIndex === 0);
                return [
                    'reply_message_id' => $replyMessageId,
                    'transport' => $transport,
                ];
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

    private function buildOutgoingReplyMessageId(string $fromEmail, array $message): string
    {
        $domain = '';
        if (strpos($fromEmail, '@') !== false) {
            $domain = (string) substr($fromEmail, strpos($fromEmail, '@') + 1);
        }
        $domain = trim($domain, "<> \t\n\r\0\x0B.");
        if ($domain === '') {
            $domain = 'localhost';
        }

        $seed = implode('|', [
            (string) (($message['message_id'] ?? null) ?: ($message['message_key'] ?? '')),
            (string) ($message['subject_normalized'] ?? ''),
            (string) ($message['from'] ?? ''),
            (string) ($message['to'] ?? ''),
            date('c'),
            function_exists('random_bytes') ? bin2hex(random_bytes(8)) : uniqid('', true),
        ]);

        return 'mail-assistant.' . substr(sha1($seed), 0, 32) . '@' . strtolower($domain);
    }

    private function resolveReplyRecipients(string $to, array $headers): array
    {
        $toEmail = $this->extractFirstEmail($to);
        if ($toEmail === '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $toEmail = strtolower(trim($to));
        }

        return [
            'to' => $toEmail,
            'cc' => $this->extractEmailsFromHeaderValue($this->extractHeaderValue($headers, 'Cc')),
            'bcc' => $this->extractEmailsFromHeaderValue($this->extractHeaderValue($headers, 'Bcc')),
        ];
    }

    private function isAssistantSentMessage(array $message): bool
    {
        $headers = is_array($message['headers_map'] ?? null) ? $message['headers_map'] : [];
        $marker = strtolower(trim((string) ($headers[strtolower(self::ASSISTANT_SENT_HEADER)] ?? '')));

        return $marker === strtolower(self::ASSISTANT_SENT_HEADER_VALUE)
            || in_array($marker, ['1', 'true', 'yes', 'on'], true);
    }

    private function buildReplyContent(string $body, array $message = [], array $rule = []): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $body));
        $requestExcerpt = $this->shouldAppendOriginalRequestExcerpt($rule)
            ? $this->buildOriginalRequestExcerpt($message)
            : '';
        $textWithExcerpt = $this->appendOriginalRequestExcerptToText($text, $requestExcerpt);

        return [
            'text' => $textWithExcerpt,
            'html' => $textWithExcerpt !== '' ? $this->buildStyledHtmlReply($text, $requestExcerpt) : '',
        ];
    }

    private function buildStyledHtmlReply(string $text, string $requestExcerpt = ''): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [];
        $htmlBlocks = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $htmlBlocks[] = '<p style="margin:0 0 16px 0;color:#111827 !important;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">'
                . nl2br($escaped, false)
                . '</p>';
        }

        if (!count($htmlBlocks)) {
            $htmlBlocks[] = '<p style="margin:0;color:#111827 !important;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">'
                . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false)
                . '</p>';
        }

        $requestExcerptHtml = $this->renderOriginalRequestExcerptHtml($requestExcerpt);

        return '<!DOCTYPE html>'
            . '<html lang="und"><head><meta charset="utf-8"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light"></head>'
            . '<body style="margin:0;padding:24px;background-color:#f5f7fb;color:#111827 !important;">'
            . '<div style="max-width:720px;margin:0 auto;background:#ffffff;color:#111827 !important;border:1px solid #e5e7eb;border-radius:12px;padding:32px 28px;box-shadow:0 1px 2px rgba(15,23,42,0.08);">'
            . implode('', $htmlBlocks)
            . $requestExcerptHtml
            . '</div></body></html>';
    }

    private function buildOriginalRequestExcerpt(array $message, int $maxLength = 900): string
    {
        $candidates = [
            (string) (($message['body_text_reply_aware'] ?? null) ?: ''),
            (string) (($message['body_text'] ?? null) ?: ''),
            (string) (($message['body_text_raw'] ?? null) ?: ''),
        ];

        foreach ($candidates as $candidate) {
            $excerpt = MimeDecoder::extractRequestSummaryText($candidate, $maxLength);
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return '';
    }

    private function appendOriginalRequestExcerptToText(string $text, string $requestExcerpt): string
    {
        $text = trim($text);
        $requestExcerpt = trim($requestExcerpt);
        if ($requestExcerpt === '') {
            return $text;
        }

        $quotedLines = array_map(static function (string $line): string {
            return '> ' . rtrim($line);
        }, preg_split('/\r\n?|\n/', $requestExcerpt) ?: []);

        return trim($text . "\n\nSummary of your request:\n" . implode("\n", $quotedLines));
    }

    private function shouldAppendOriginalRequestExcerpt(array $rule): bool
    {
        $reply = (array) ($rule['reply'] ?? []);
        $customInstruction = strtolower(trim((string) (($reply['custom_instruction'] ?? null) ?: '')));
        if ($customInstruction === '') {
            return true;
        }

        if (preg_match('/\bwrite only the email body\b/u', $customInstruction) === 1) {
            return false;
        }

        if (preg_match('/\bemail body only\b/u', $customInstruction) === 1) {
            return false;
        }

        return true;
    }

    private function validateAiReplyAgainstInstruction(string $text, array $mailbox, array $rule, array $message): ?string
    {
        $reply = (array) ($rule['reply'] ?? []);
        $instruction = trim((string) (($reply['custom_instruction'] ?? null) ?: ''));
        if ($instruction === '') {
            return null;
        }

        $normalizedInstruction = $this->normalizeInstructionText($instruction);
        $normalizedReply = $this->normalizeInstructionText($text);
        if ($normalizedReply === '') {
            return 'AI reply was empty after generation.';
        }

        if ($this->instructionRequiresEnglishOnly($normalizedInstruction) && $this->looksSwedish($normalizedReply)) {
            return 'AI reply violated the explicit English-only requirement.';
        }

        $requiredEmails = $this->extractEmailsFromHeaderValue($instruction);
        foreach ($requiredEmails as $email) {
            if (stripos($text, $email) === false) {
                return 'AI reply omitted the required contact address: ' . $email;
            }
        }

        $mustStateBullets = $this->extractMustStateBullets($instruction);
        foreach ($mustStateBullets as $bullet) {
            if (!$this->replySatisfiesBulletRequirement($normalizedReply, $bullet)) {
                return 'AI reply did not satisfy the required statement: ' . $bullet;
            }
        }

        if ($this->instructionRequiresRedirectOnly($normalizedInstruction) && $this->replyContainsForbiddenResponsibilityClaim($normalizedReply)) {
            return 'AI reply contradicts the redirect-only / no-responsibility instruction.';
        }

        return null;
    }

    private function normalizeInstructionText(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function instructionRequiresEnglishOnly(string $normalizedInstruction): bool
    {
        return strpos($normalizedInstruction, 'write only the email body in english') !== false
            || strpos($normalizedInstruction, 'write the reply in english') !== false
            || strpos($normalizedInstruction, 'reply in english') !== false
            || strpos($normalizedInstruction, 'english only') !== false;
    }

    private function instructionRequiresRedirectOnly(string $normalizedInstruction): bool
    {
        return (strpos($normalizedInstruction, 'does not handle these notices') !== false
                || strpos($normalizedInstruction, 'not the proper recipient') !== false
                || strpos($normalizedInstruction, 'received by tornevall networks in error') !== false)
            && strpos($normalizedInstruction, 'must rerun the process') !== false;
    }

    private function looksSwedish(string $normalizedReply): bool
    {
        $markers = [
            ' tack ', ' vänlig ', ' hälsning', ' med vänlig', ' vi ', ' och ', ' att ', ' inte ', ' detta ',
            ' kommer ', ' undersöka ', ' situationen ', ' uppgifterna ', ' notifiering ', ' till ', ' från ',
        ];
        $haystack = ' ' . $normalizedReply . ' ';
        $hits = 0;
        foreach ($markers as $marker) {
            if (strpos($haystack, $marker) !== false) {
                $hits++;
            }
        }

        return $hits >= 3;
    }

    private function extractMustStateBullets(string $instruction): array
    {
        $lines = preg_split('/\r\n?|\n/', $instruction) ?: [];
        $collect = false;
        $bullets = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            $normalized = strtolower($trimmed);
            if ($normalized === '') {
                if ($collect && count($bullets)) {
                    break;
                }
                continue;
            }

            if (strpos($normalized, 'the reply must state that') === 0) {
                $collect = true;
                continue;
            }

            if (!$collect) {
                continue;
            }

            if (strpos($trimmed, '-') === 0) {
                $bullets[] = ltrim(substr($trimmed, 1));
                continue;
            }

            if (preg_match('/^[a-z].*:/i', $trimmed) === 1) {
                break;
            }
        }

        return array_values(array_filter(array_map('trim', $bullets), static function (string $bullet): bool {
            return $bullet !== '';
        }));
    }

    private function replySatisfiesBulletRequirement(string $normalizedReply, string $bullet): bool
    {
        $normalizedBullet = $this->normalizeInstructionText($bullet);

        if (strpos($normalizedBullet, 'in error') !== false) {
            return $this->containsAnyPhrase($normalizedReply, ['in error', 'by mistake', 'mistakenly', 'misdirected', 'wrong recipient']);
        }

        if (strpos($normalizedBullet, 'does not handle these notices') !== false || strpos($normalizedBullet, 'not the proper recipient') !== false) {
            return $this->containsAnyPhrase($normalizedReply, ['does not handle', 'do not handle', 'not the proper recipient', 'not the correct recipient', 'not responsible for these notices']);
        }

        if (strpos($normalizedBullet, 'being deleted') !== false || strpos($normalizedBullet, 'deleted instead of handled') !== false) {
            return $this->containsAnyPhrase($normalizedReply, ['deleted', 'deleting this message', 'this message will be deleted']);
        }

        if ((strpos($normalizedBullet, 'rerun the process') !== false || strpos($normalizedBullet, 'submit the notice directly') !== false)) {
            return $this->containsAnyPhrase($normalizedReply, ['rerun the process', 'submit the notice directly', 'resubmit', 'submit it directly'])
                && $this->containsAnyPhrase($normalizedReply, ['abuse@no-ack.net']);
        }

        if (strpos($normalizedBullet, 'future notices') !== false) {
            return $this->containsAnyPhrase($normalizedReply, ['future notices', 'in the future', 'future notices of this kind'])
                && $this->containsAnyPhrase($normalizedReply, ['abuse@no-ack.net']);
        }

        $keywords = array_values(array_filter(preg_split('/[^a-z0-9@._-]+/i', $normalizedBullet) ?: [], static function (string $token): bool {
            static $stop = ['the', 'and', 'that', 'this', 'must', 'also', 'with', 'from', 'into', 'there', 'them', 'they', 'have', 'been', 'will', 'your', 'current', 'future', 'same', 'kind'];
            return strlen($token) >= 5 && !in_array($token, $stop, true);
        }));
        $matched = 0;
        foreach ($keywords as $keyword) {
            if (strpos($normalizedReply, $keyword) !== false) {
                $matched++;
            }
        }

        return $matched >= min(2, count($keywords));
    }

    private function containsAnyPhrase(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && strpos($haystack, strtolower($phrase)) !== false) {
                return true;
            }
        }

        return false;
    }

    private function replyContainsForbiddenResponsibilityClaim(string $normalizedReply): bool
    {
        $forbiddenPhrases = [
            'we will investigate',
            'we are investigating',
            'we will look into',
            'we take this seriously and will investigate',
            'we will remove',
            'we are removing',
            'we will see to remove',
            'we will handle',
            'we are handling',
            'we will contact you if we have questions',
        ];

        return $this->containsAnyPhrase($normalizedReply, $forbiddenPhrases);
    }

    private function renderOriginalRequestExcerptHtml(string $requestExcerpt): string
    {
        $requestExcerpt = trim($requestExcerpt);
        if ($requestExcerpt === '') {
            return '';
        }

        return '<div style="margin:18px 0 0 0;padding:16px;background:#f8fafc;color:#111827 !important;border-left:4px solid #cbd5e1;border-radius:8px;">'
            . '<div style="margin:0 0 10px 0;font-weight:bold;color:#111827 !important;font-family:Arial,Helvetica,sans-serif;font-size:14px;">Summary of your request</div>'
            . '<div style="color:#334155 !important;white-space:pre-line;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;">'
            . nl2br(htmlspecialchars($requestExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false)
            . '</div>'
            . '</div>';
    }

    private function describeConfiguredAiModelTrail(array $replyConfig): string
    {
        $primary = trim((string) (($replyConfig['ai_model'] ?? null) ?: Env::get('MAIL_ASSISTANT_AI_MODEL', 'gpt-5.4')));
        $fallback = trim((string) Env::get('MAIL_ASSISTANT_AI_FALLBACK_MODEL', 'o4'));

        return $this->formatModelTrail($primary, $fallback);
    }

    private function describeAiModelTrailFromResult(array $aiResult, string $fallbackTrail = ''): string
    {
        $model = trim((string) ($aiResult['model'] ?? ''));
        $fallbackFrom = trim((string) ($aiResult['fallback_from_model'] ?? ''));
        $trail = $this->formatModelTrail($fallbackFrom, $model);

        return $trail !== '' ? $trail : $fallbackTrail;
    }

    private function formatModelTrail(string $primaryModel, string $fallbackModel = ''): string
    {
        $models = array_values(array_filter([
            trim($primaryModel),
            trim($fallbackModel),
        ], static function (string $model): bool {
            return $model !== '';
        }));

        return implode(' -> ', array_values(array_unique($models)));
    }

    private function buildTransportMessageParts(array $headers, array $replyContent): array
    {
        $text = (string) ($replyContent['text'] ?? '');
        $html = trim((string) ($replyContent['html'] ?? ''));
        $transportHeaders = ['MIME-Version: 1.0'];

        if ($html !== '') {
            $boundary = $this->buildMimeBoundary();
            $transportHeaders[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            return [
                'headers' => array_merge($transportHeaders, $headers),
                'body' => $this->buildMultipartAlternativeBody($boundary, $text, $html),
            ];
        }

        $transportHeaders[] = 'Content-Type: text/plain; charset=UTF-8';

        return [
            'headers' => array_merge($transportHeaders, $headers),
            'body' => $text,
        ];
    }

    private function buildMimeBoundary(): string
    {
        $random = function_exists('random_bytes')
            ? bin2hex(random_bytes(12))
            : md5(uniqid('mail_assistant_', true));

        return '=_MailAssistant_' . $random;
    }

    private function buildMultipartAlternativeBody(string $boundary, string $text, string $html): string
    {
        return implode("\r\n", [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $text,
            '',
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $html,
            '',
            '--' . $boundary . '--',
        ]);
    }

    private function buildRawTransportMessage(string $to, string $subject, array $headers, string $body, bool $excludeBcc = false): string
    {
        if ($excludeBcc) {
            $headers = array_values(array_filter($headers, static function (string $header): bool {
                return stripos($header, 'Bcc:') !== 0;
            }));
        }

        return implode("\r\n", array_merge([
            'To: ' . $to,
            'Subject: ' . $subject,
        ], $headers, ['', $body]));
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

    private function deliverReplyViaTransport(string $transport, array $mailbox, array $rule, array $message, string $to, string $subject, array $replyContent, array $headers, array $resolvedRecipients, bool $isPrimary): void
    {
        if ($transport === 'tools_api') {
            $this->sendReplyViaToolsRelay(
                $mailbox,
                $rule,
                $message,
                $to,
                $subject,
                $replyContent,
                $headers,
                $resolvedRecipients,
                $isPrimary ? 'tools_api_primary' : 'fallback_after_transport_failure'
            );
            return;
        }

        if ($transport === 'smtp') {
            $this->sendReplyViaSmtp($to, $subject, $replyContent, $headers, $resolvedRecipients);
            $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'smtp', 'cc' => $resolvedRecipients['cc'], 'bcc' => $resolvedRecipients['bcc']]);
            return;
        }

        if ($transport === 'pickup') {
            $this->sendReplyViaPickup($to, $subject, $replyContent, $headers);
            $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'pickup', 'cc' => $resolvedRecipients['cc'], 'bcc' => $resolvedRecipients['bcc']]);
            return;
        }

        if ($transport === 'custom_mta') {
            $this->sendReplyViaCustomMta($to, $subject, $replyContent, $headers);
            $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'custom_mta', 'cc' => $resolvedRecipients['cc'], 'bcc' => $resolvedRecipients['bcc']]);
            return;
        }

        $this->sendReplyViaPhpMail($to, $subject, $replyContent, $headers);
        $this->logger->info('Reply sent.', ['to' => $to, 'subject' => $subject, 'transport' => 'php_mail', 'cc' => $resolvedRecipients['cc'], 'bcc' => $resolvedRecipients['bcc']]);
    }

    private function sendReplyViaPhpMail(string $to, string $subject, array $replyContent, array $headers): void
    {
        $messageParts = $this->buildTransportMessageParts($headers, $replyContent);
        if (!@mail($to, $subject, (string) $messageParts['body'], implode("\r\n", (array) $messageParts['headers']))) {
            throw new RuntimeException('PHP mail() failed while sending a reply.');
        }
    }

    private function sendReplyViaCustomMta(string $to, string $subject, array $replyContent, array $headers): void
    {
        $command = trim((string) Env::get('MAIL_ASSISTANT_MTA_COMMAND', ''));
        if ($command === '') {
            throw new RuntimeException('MAIL_ASSISTANT_MTA_COMMAND is not configured.');
        }

        $process = @popen($command, 'w');
        if (!is_resource($process)) {
            throw new RuntimeException('Could not open custom MTA command: ' . $command);
        }

        $messageParts = $this->buildTransportMessageParts($headers, $replyContent);
        $rawMessage = $this->buildRawTransportMessage(
            $to,
            $subject,
            (array) $messageParts['headers'],
            (string) $messageParts['body']
        );

        fwrite($process, $rawMessage);
        $result = pclose($process);
        if ($result !== 0) {
            throw new RuntimeException('Custom MTA command failed with exit code ' . $result . '.');
        }
    }

    private function sendReplyViaPickup(string $to, string $subject, array $replyContent, array $headers): void
    {
        $dir = rtrim(trim((string) Env::get('MAIL_ASSISTANT_PICKUP_DIR', '')), '/\\');
        if ($dir === '') {
            throw new RuntimeException('MAIL_ASSISTANT_PICKUP_DIR is not configured.');
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('MAIL_ASSISTANT_PICKUP_DIR is not a writable directory: ' . $dir);
        }

        $filename = $dir . DIRECTORY_SEPARATOR . 'mail-' . uniqid('', true) . '.msg';
        $messageParts = $this->buildTransportMessageParts($headers, $replyContent);
        $rawMessage = $this->buildRawTransportMessage(
            $to,
            $subject,
            (array) $messageParts['headers'],
            (string) $messageParts['body']
        );

        if (@file_put_contents($filename, $rawMessage) === false) {
            throw new RuntimeException('Could not write message to pickup directory: ' . $filename);
        }
    }

    private function sendReplyViaSmtp(string $to, string $subject, array $replyContent, array $headers, array $resolvedRecipients): void
    {
        $host = trim((string) Env::get('MAIL_ASSISTANT_SMTP_HOST', ''));
        $port = (int) Env::get('MAIL_ASSISTANT_SMTP_PORT', '587');
        $security = strtolower(trim((string) Env::get('MAIL_ASSISTANT_SMTP_SECURITY', '')));
        $username = trim((string) Env::get('MAIL_ASSISTANT_SMTP_USERNAME', ''));
        $password = (string) Env::get('MAIL_ASSISTANT_SMTP_PASSWORD', '');
        $ehlo = trim((string) Env::get('MAIL_ASSISTANT_SMTP_EHLO', ''));
        $timeout = max(5, (int) Env::get('MAIL_ASSISTANT_SMTP_TIMEOUT', '20'));

        if ($host === '') {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_HOST is not configured.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_PORT is invalid.');
        }
        if ($security === '') {
            $security = 'tls';
        }
        if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_SECURITY must be tls, ssl, or none.');
        }
        if ($ehlo === '') {
            $ehlo = php_uname('n');
        }
        if ($ehlo === '') {
            $ehlo = 'localhost';
        }

        $fromHeader = $this->extractHeaderValue($headers, 'From');
        $fromEmail = $this->extractFirstEmail($fromHeader);
        if ($fromEmail === '') {
            throw new RuntimeException('Could not resolve sender email from From header for SMTP delivery.');
        }

        $envelopeFrom = trim((string) Env::get('MAIL_ASSISTANT_SMTP_FROM_ENVELOPE', ''));
        if ($envelopeFrom === '') {
            $envelopeFrom = $fromEmail;
        }
        if (!filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_ASSISTANT_SMTP_FROM_ENVELOPE is invalid.');
        }

        $primaryRecipient = trim((string) ($resolvedRecipients['to'] ?? ''));
        $envelopeRecipients = array_values(array_unique(array_filter(array_merge(
            $primaryRecipient !== '' ? [$primaryRecipient] : [],
            array_values((array) ($resolvedRecipients['cc'] ?? [])),
            array_values((array) ($resolvedRecipients['bcc'] ?? []))
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

            $messageParts = $this->buildTransportMessageParts($headers, $replyContent);
            $raw = $this->buildRawTransportMessage(
                $to,
                $subject,
                (array) $messageParts['headers'],
                (string) $messageParts['body'],
                true
            );
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
        $headerValue = str_replace(["\r", "\n", ';'], [',', ',', ','], $headerValue);
        $result = [];
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}/i', $headerValue, $matches) === 1 || !empty($matches[0])) {
            foreach ((array) ($matches[0] ?? []) as $email) {
                $email = strtolower(trim((string) $email));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result[] = $email;
                }
            }
        }

        if (!count($result)) {
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
        }

        return array_values(array_unique($result));
    }

    private function sendReplyViaToolsRelay(
        array $mailbox,
        array $rule,
        array $message,
        string $to,
        string $subject,
        array $replyContent,
        array $headers,
        array $resolvedRecipients,
        string $mode
    ): void {
        $fromHeader = '';
        foreach ($headers as $header) {
            if (stripos($header, 'From:') === 0) {
                $fromHeader = trim(substr($header, 5));
                break;
            }
        }

        $this->tools->sendReplyViaTools([
            'mailbox_id' => (int) ($mailbox['id'] ?? 0),
            'rule_id' => (int) ($rule['id'] ?? 0),
            'mode' => $mode,
            'to' => trim((string) (($resolvedRecipients['to'] ?? null) ?: $to)),
            'cc' => array_values(array_filter((array) ($resolvedRecipients['cc'] ?? []))),
            'bcc' => array_values(array_filter((array) ($resolvedRecipients['bcc'] ?? []))),
            'from' => $fromHeader,
            'subject' => $subject,
            'body' => (string) ($replyContent['text'] ?? ''),
            'body_html' => (string) ($replyContent['html'] ?? ''),
            'message_meta' => [
                'message_id' => (string) ($message['message_id'] ?? ''),
                'reply_message_id' => (string) (MimeDecoder::normalizeMessageId($this->extractHeaderValue($headers, 'Message-ID')) ?: ''),
                'uid' => (int) ($message['uid'] ?? 0),
                'from' => (string) ($message['from'] ?? ''),
                'to' => (string) ($message['to'] ?? ''),
                'date' => (string) ($message['date'] ?? ''),
            ],
        ]);

        $this->logger->info('Reply sent.', [
            'to' => $to,
            'subject' => $subject,
            'transport' => 'tools_api',
            'mode' => $mode,
            'cc' => $resolvedRecipients['cc'] ?? [],
            'bcc' => $resolvedRecipients['bcc'] ?? [],
        ]);
    }
}


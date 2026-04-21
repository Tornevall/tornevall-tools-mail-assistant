<?php

namespace MailSupportAssistant\Support;

use MailSupportAssistant\Mail\MimeDecoder;

class MessageStateStore
{
    private string $stateFile;

    public function __construct(?string $stateFile = null)
    {
        $this->stateFile = $stateFile ?: ProjectPaths::messageStateFile();
    }

    public function hasRecord(int $mailboxId, string $messageKey): bool
    {
        return $this->getRecord($mailboxId, $messageKey) !== null;
    }

    public function getRecord(int $mailboxId, string $messageKey): ?array
    {
        $messageKey = $this->normalizeKey($messageKey);
        if ($mailboxId < 1 || $messageKey === '') {
            return null;
        }

        $state = $this->load();
        $record = $state['mailboxes'][(string) $mailboxId]['messages'][$messageKey] ?? null;

        return is_array($record) ? $record : null;
    }

    public function remember(int $mailboxId, string $messageKey, array $payload): void
    {
        $messageKey = $this->normalizeKey($messageKey);
        if ($mailboxId < 1 || $messageKey === '') {
            return;
        }

        $state = $this->load();
        $mailboxKey = (string) $mailboxId;
        if (!isset($state['mailboxes'][$mailboxKey]) || !is_array($state['mailboxes'][$mailboxKey])) {
            $state['mailboxes'][$mailboxKey] = [
                'messages' => [],
            ];
        }

        $messages = is_array($state['mailboxes'][$mailboxKey]['messages'] ?? null)
            ? $state['mailboxes'][$mailboxKey]['messages']
            : [];

        $record = is_array($payload) ? $payload : [];
        if (!isset($record['message_id'])) {
            $record['message_id'] = '';
        }
        if (!isset($record['status'])) {
            $record['status'] = 'recorded';
        }
        if (!isset($record['reason'])) {
            $record['reason'] = '';
        }
        $record['message_key'] = $messageKey;
        $record['mailbox_id'] = $mailboxId;
        $record['recorded_at'] = date('c');

        $messages[$messageKey] = $record;

        $maxPerMailbox = max(100, (int) Env::get('MAIL_ASSISTANT_MESSAGE_STATE_MAX_PER_MAILBOX', '5000'));
        if (count($messages) > $maxPerMailbox) {
            uasort($messages, static function (array $a, array $b): int {
                return strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''));
            });
            $messages = array_slice($messages, 0, $maxPerMailbox, true);
        }

        $state['mailboxes'][$mailboxKey]['messages'] = $messages;
        $state['updated_at'] = date('c');
        $this->save($state);
    }

    public function summary(): array
    {
        $state = $this->load();
        $mailboxes = [];
        $total = 0;

        foreach ((array) ($state['mailboxes'] ?? []) as $mailboxId => $mailboxState) {
            $messages = array_values((array) ($mailboxState['messages'] ?? []));
            usort($messages, static function (array $a, array $b): int {
                return strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''));
            });

            $excludedReadRecords = 0;
            $visibleMessages = array_values(array_filter($messages, static function (array $message) use (&$excludedReadRecords): bool {
                $reason = strtolower(trim((string) ($message['reason'] ?? '')));
                if ($reason === 'already_read_at_ingest') {
                    $excludedReadRecords++;
                    return false;
                }

                return true;
            }));

            $statusCounts = [];
            foreach ($visibleMessages as $message) {
                $status = (string) ($message['status'] ?? 'recorded');
                if (!isset($statusCounts[$status])) {
                    $statusCounts[$status] = 0;
                }
                $statusCounts[$status]++;
            }

            // recent: only show messages that are NOT already handled — i.e. still need attention.
            $pendingMessages = array_values(array_filter($visibleMessages, static function (array $message): bool {
                return strtolower(trim((string) ($message['status'] ?? ''))) !== 'handled';
            }));

            $mailboxes[(string) $mailboxId] = [
                'count' => count($visibleMessages),
                'count_pending' => count($pendingMessages),
                'count_already_replied' => count($visibleMessages) - count($pendingMessages),
                'excluded_read_records' => $excludedReadRecords,
                'raw_count' => count($messages),
                'status_counts' => $statusCounts,
                'recent_all' => array_slice(array_map(static function (array $message): array {
                    $summary = [
                        'message_id' => (string) ($message['message_id'] ?? ''),
                        'message_key' => (string) ($message['message_key'] ?? ''),
                        'reply_message_id' => (string) ($message['reply_message_id'] ?? ''),
                        'reply_issue_id' => (string) ($message['reply_issue_id'] ?? ''),
                        'status' => (string) ($message['status'] ?? ''),
                        'reason' => (string) ($message['reason'] ?? ''),
                        'subject' => (string) ($message['subject'] ?? ''),
                        'recorded_at' => (string) ($message['recorded_at'] ?? ''),
                    ];

                    if (array_key_exists('selected_rule', $message)) {
                        $summary['selected_rule'] = is_array($message['selected_rule']) ? $message['selected_rule'] : null;
                    }
                    if (array_key_exists('matching_rule_count', $message)) {
                        $summary['matching_rule_count'] = (int) $message['matching_rule_count'];
                    }
                    if (array_key_exists('matching_rules', $message)) {
                        $summary['matching_rules'] = is_array($message['matching_rules']) ? $message['matching_rules'] : [];
                    }

                    return $summary;
                }, $visibleMessages), 0, 10),
                'recent' => array_slice(array_map(static function (array $message): array {
                    $summary = [
                        'message_id' => (string) ($message['message_id'] ?? ''),
                        'message_key' => (string) ($message['message_key'] ?? ''),
                        'reply_message_id' => (string) ($message['reply_message_id'] ?? ''),
                        'reply_issue_id' => (string) ($message['reply_issue_id'] ?? ''),
                        'status' => (string) ($message['status'] ?? ''),
                        'reason' => (string) ($message['reason'] ?? ''),
                        'subject' => (string) ($message['subject'] ?? ''),
                        'recorded_at' => (string) ($message['recorded_at'] ?? ''),
                    ];

                    if (array_key_exists('selected_rule', $message)) {
                        $summary['selected_rule'] = is_array($message['selected_rule']) ? $message['selected_rule'] : null;
                    }
                    if (array_key_exists('matching_rule_count', $message)) {
                        $summary['matching_rule_count'] = (int) $message['matching_rule_count'];
                    }
                    if (array_key_exists('matching_rules', $message)) {
                        $summary['matching_rules'] = is_array($message['matching_rules']) ? $message['matching_rules'] : [];
                    }

                    return $summary;
                }, $pendingMessages), 0, 10),
            ];
            $total += count($visibleMessages);
        }

        return [
            'state_file' => $this->stateFile,
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'mailboxes' => $mailboxes,
            'total_records' => $total,
        ];
    }

    public function summarizeThread(int $mailboxId, array $message, int $maxMessages = 6): array
    {
        $threadKey = $this->resolveThreadKeyFromMessage($message);
        $messageId = $this->normalizeKey((string) ($message['message_id'] ?? ''));
        $references = array_values(array_filter(array_map([$this, 'normalizeKey'], (array) ($message['references'] ?? []))));
        $inReplyTo = $this->normalizeKey((string) ($message['in_reply_to'] ?? ''));

        if ($mailboxId < 1) {
            return [
                'thread_key' => $threadKey,
                'messages' => [],
            ];
        }

        $state = $this->load();
        $messages = array_values((array) ($state['mailboxes'][(string) $mailboxId]['messages'] ?? []));
        $threadMessages = array_values(array_filter($messages, function (array $record) use ($threadKey, $messageId, $references, $inReplyTo): bool {
            $recordThreadKey = $this->normalizeKey((string) ($record['thread_key'] ?? ''));
            $recordMessageId = $this->normalizeKey((string) ($record['message_id'] ?? ''));

            if ($threadKey !== '' && $recordThreadKey !== '' && $threadKey === $recordThreadKey) {
                return true;
            }
            if ($messageId !== '' && $recordMessageId === $messageId) {
                return true;
            }
            if ($recordMessageId !== '' && in_array($recordMessageId, $references, true)) {
                return true;
            }
            if ($inReplyTo !== '' && $recordMessageId === $inReplyTo) {
                return true;
            }

            return false;
        }));

        if (!count($threadMessages)) {
            $threadMessages = $this->findSubjectParticipantContinuityMatches($messages, $message);
        }

        usort($threadMessages, static function (array $a, array $b): int {
            return strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? ''));
        });

        if ($maxMessages > 0 && count($threadMessages) > $maxMessages) {
            $threadMessages = array_slice($threadMessages, -$maxMessages);
        }

        $threadMessages = array_values(array_map(static function (array $record): array {
            $summary = [
                'message_id' => (string) ($record['message_id'] ?? ''),
                'reply_message_id' => (string) ($record['reply_message_id'] ?? ''),
                'reply_issue_id' => (string) ($record['reply_issue_id'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'reason' => (string) ($record['reason'] ?? ''),
                'subject' => (string) ($record['subject'] ?? ''),
                'from' => (string) ($record['from'] ?? ''),
                'to' => (string) ($record['to'] ?? ''),
                'date' => (string) ($record['date'] ?? ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'body_excerpt' => (string) ($record['body_excerpt'] ?? ''),
                'reply_excerpt' => (string) ($record['reply_excerpt'] ?? ''),
            ];

            if (is_array($record['selected_rule'] ?? null)) {
                $summary['selected_rule'] = [
                    'id' => (int) ($record['selected_rule']['id'] ?? 0),
                    'name' => (string) ($record['selected_rule']['name'] ?? ''),
                    'sort_order' => (int) ($record['selected_rule']['sort_order'] ?? 0),
                ];
            }

            if (is_array($record['generic_ai_decision'] ?? null)) {
                $summary['generic_ai_decision'] = [
                    'matched_no_match_rule_id' => (int) ($record['generic_ai_decision']['matched_no_match_rule_id'] ?? 0),
                    'matched_no_match_rule_order' => (int) ($record['generic_ai_decision']['matched_no_match_rule_order'] ?? 0),
                    'decision_reason_code' => (string) ($record['generic_ai_decision']['decision_reason_code'] ?? ''),
                    'reason' => (string) ($record['generic_ai_decision']['reason'] ?? ''),
                ];
            }

            return $summary;
        }, $threadMessages));

        return [
            'thread_key' => $threadKey,
            'messages' => $threadMessages,
        ];
    }

    public function findLatestThreadHandlingRecord(int $mailboxId, array $message): ?array
    {
        if ($mailboxId < 1) {
            return null;
        }

        $state = $this->load();
        $messages = array_values((array) ($state['mailboxes'][(string) $mailboxId]['messages'] ?? []));
        $threadLinks = $this->resolveExplicitThreadLinksFromMessage($message);
        $matchingRecords = array_values(array_filter($messages, function (array $record) use ($threadLinks): bool {
            if (!count($threadLinks)) {
                return false;
            }

            return $this->recordHasReusableHandling($record)
                && $this->recordMatchesThreadLinks($record, $threadLinks);
        }));

        if (count($matchingRecords)) {
            usort($matchingRecords, function (array $a, array $b) use ($threadLinks): int {
                $scoreCompare = $this->recordThreadLinkScore($b, $threadLinks) <=> $this->recordThreadLinkScore($a, $threadLinks);
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                return strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''));
            });

            $record = $matchingRecords[0] ?? null;
            if (is_array($record)) {
                $record['thread_match_mode'] = 'explicit_links';
                $record['thread_link_score'] = $this->recordThreadLinkScore($record, $threadLinks);
            }

            return $record;
        }

        $fallbackMatches = array_values(array_filter(
            $this->findSubjectParticipantContinuityMatches($messages, $message),
            fn (array $record): bool => $this->recordHasReusableHandling($record)
        ));

        if (!count($fallbackMatches)) {
            return null;
        }

        usort($fallbackMatches, static function (array $a, array $b): int {
            return strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''));
        });

        $record = $fallbackMatches[0] ?? null;
        if (is_array($record)) {
            $record['thread_match_mode'] = 'subject_participants';
            $record['thread_link_score'] = 0;
        }

        return $record;
    }

    private function load(): array
    {
        if (!is_readable($this->stateFile)) {
            return [
                'updated_at' => '',
                'mailboxes' => [],
            ];
        }

        $decoded = json_decode((string) file_get_contents($this->stateFile), true);

        return is_array($decoded)
            ? array_merge(['updated_at' => '', 'mailboxes' => []], $decoded)
            : ['updated_at' => '', 'mailboxes' => []];
    }

    private function save(array $state): void
    {
        ProjectPaths::ensureStorage();
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeKey(string $messageKey): string
    {
        return strtolower(trim($messageKey, "<> \t\n\r\0\x0B"));
    }

    private function resolveExplicitThreadLinksFromMessage(array $message): array
    {
        $links = [];

        $inReplyTo = $this->normalizeKey((string) ($message['in_reply_to'] ?? ''));
        if ($inReplyTo !== '') {
            $links[$inReplyTo] = true;
        }

        foreach ((array) ($message['references'] ?? []) as $reference) {
            $normalized = $this->normalizeKey((string) $reference);
            if ($normalized !== '') {
                $links[$normalized] = true;
            }
        }

        $messageId = $this->normalizeKey((string) ($message['message_id'] ?? ''));
        if ($messageId !== '') {
            $links[$messageId] = true;
        }

        return array_keys($links);
    }

    private function recordHasReusableHandling(array $record): bool
    {
        if ($this->normalizeKey((string) ($record['status'] ?? '')) !== 'handled') {
            return false;
        }

        if ($this->extractSelectedRuleId($record) > 0) {
            return true;
        }

        return $this->extractMatchedNoMatchRuleId($record) > 0;
    }

    private function recordMatchesThreadLinks(array $record, array $threadLinks): bool
    {
        return $this->recordThreadLinkScore($record, $threadLinks) > 0;
    }

    private function recordThreadLinkScore(array $record, array $threadLinks): int
    {
        $candidates = [];

        foreach (['message_id', 'reply_message_id', 'thread_key', 'in_reply_to'] as $field) {
            $value = $this->normalizeKey((string) ($record[$field] ?? ''));
            if ($value !== '') {
                $candidates[$field][$value] = true;
            }
        }

        foreach ((array) ($record['references'] ?? []) as $reference) {
            $value = $this->normalizeKey((string) $reference);
            if ($value !== '') {
                $candidates['references'][$value] = true;
            }
        }

        $score = 0;
        foreach ($threadLinks as $link) {
            $normalized = $this->normalizeKey((string) $link);
            if ($normalized === '') {
                continue;
            }

            if (isset($candidates['reply_message_id'][$normalized])) {
                $score = max($score, 60);
            }
            if (isset($candidates['message_id'][$normalized])) {
                $score = max($score, 50);
            }
            if (isset($candidates['in_reply_to'][$normalized])) {
                $score = max($score, 40);
            }
            if (isset($candidates['references'][$normalized])) {
                $score = max($score, 30);
            }
            if (isset($candidates['thread_key'][$normalized])) {
                $score = max($score, 20);
            }
        }

        return $score;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function findSubjectParticipantContinuityMatches(array $messages, array $message): array
    {
        $subjectKey = $this->normalizeSubjectKey((string) (($message['subject_normalized'] ?? null) ?: ($message['subject'] ?? '')));
        if ($subjectKey === '') {
            return [];
        }

        $participants = $this->extractParticipantAddresses([
            'from' => (string) ($message['from'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
        ]);
        if (!count($participants)) {
            return [];
        }

        return array_values(array_filter($messages, function (array $record) use ($subjectKey, $participants): bool {
            return $this->recordMatchesSubjectParticipantFallback($record, $subjectKey, $participants);
        }));
    }

    private function recordMatchesSubjectParticipantFallback(array $record, string $subjectKey, array $participants): bool
    {
        $recordSubjectKey = $this->normalizeSubjectKey((string) (($record['subject_normalized'] ?? null) ?: ($record['subject'] ?? '')));
        if ($recordSubjectKey === '') {
            $recordThreadKey = $this->normalizeKey((string) ($record['thread_key'] ?? ''));
            if (strpos($recordThreadKey, 'subject:') === 0) {
                $recordSubjectKey = substr($recordThreadKey, 8);
            }
        }

        if ($recordSubjectKey === '' || $recordSubjectKey !== $subjectKey) {
            return false;
        }

        $recordParticipants = $this->extractParticipantAddresses($record);
        if (!count($recordParticipants)) {
            return false;
        }

        $sharedParticipants = array_values(array_intersect($participants, $recordParticipants));

        return count($sharedParticipants) >= min(2, count($participants), count($recordParticipants));
    }

    private function normalizeSubjectKey(string $subject): string
    {
        $normalized = MimeDecoder::normalizeReplySubject($subject);

        return $this->normalizeKey($normalized);
    }

    private function extractParticipantAddresses(array $record): array
    {
        $addresses = [];
        foreach (['from', 'to'] as $field) {
            foreach ($this->extractEmails((string) ($record[$field] ?? '')) as $email) {
                $addresses[$email] = true;
            }
        }

        return array_keys($addresses);
    }

    private function extractEmails(string $value): array
    {
        $value = str_replace(["\r", "\n", ';'], [',', ',', ','], $value);
        $emails = [];

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}/i', $value, $matches) === 1 || !empty($matches[0])) {
            foreach ((array) ($matches[0] ?? []) as $email) {
                $normalized = strtolower(trim((string) $email));
                if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                    $emails[$normalized] = true;
                }
            }
        }

        if (!count($emails)) {
            $candidate = strtolower(trim($value, " \t\n\r\0\x0B<>\"'"));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $emails[$candidate] = true;
            }
        }

        return array_keys($emails);
    }

    private function extractSelectedRuleId(array $record): int
    {
        if (!is_array($record['selected_rule'] ?? null)) {
            return 0;
        }

        return (int) ($record['selected_rule']['id'] ?? 0);
    }

    private function extractMatchedNoMatchRuleId(array $record): int
    {
        if (!is_array($record['generic_ai_decision'] ?? null)) {
            return 0;
        }

        return (int) ($record['generic_ai_decision']['matched_no_match_rule_id'] ?? 0);
    }

    private function resolveThreadKeyFromMessage(array $message): string
    {
        $references = array_values(array_filter(array_map([$this, 'normalizeKey'], (array) ($message['references'] ?? []))));
        if (count($references)) {
            return $references[0];
        }

        $inReplyTo = $this->normalizeKey((string) ($message['in_reply_to'] ?? ''));
        if ($inReplyTo !== '') {
            return $inReplyTo;
        }

        $subjectNormalized = $this->normalizeKey((string) ($message['subject_normalized'] ?? ''));
        if ($subjectNormalized !== '') {
            return 'subject:' . $subjectNormalized;
        }

        return $this->normalizeKey((string) ($message['message_id'] ?? ''));
    }

    public function purge(): void
    {
        $state = $this->load();
        $state['mailboxes'] = [];
        $state['updated_at'] = date('c');
        $this->save($state);
    }
}


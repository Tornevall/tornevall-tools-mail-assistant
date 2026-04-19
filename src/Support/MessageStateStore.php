<?php

namespace MailSupportAssistant\Support;

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

        usort($threadMessages, static function (array $a, array $b): int {
            return strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? ''));
        });

        if ($maxMessages > 0 && count($threadMessages) > $maxMessages) {
            $threadMessages = array_slice($threadMessages, -$maxMessages);
        }

        $threadMessages = array_values(array_map(static function (array $record): array {
            return [
                'message_id' => (string) ($record['message_id'] ?? ''),
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
        }, $threadMessages));

        return [
            'thread_key' => $threadKey,
            'messages' => $threadMessages,
        ];
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
        return strtolower(trim($messageKey));
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


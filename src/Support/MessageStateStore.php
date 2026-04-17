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
        $messageKey = $this->normalizeKey($messageKey);
        if ($mailboxId < 1 || $messageKey === '') {
            return false;
        }

        $state = $this->load();

        return isset($state['mailboxes'][(string) $mailboxId]['messages'][$messageKey]);
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

            $statusCounts = [];
            foreach ($messages as $message) {
                $status = (string) ($message['status'] ?? 'recorded');
                if (!isset($statusCounts[$status])) {
                    $statusCounts[$status] = 0;
                }
                $statusCounts[$status]++;
            }

            $mailboxes[(string) $mailboxId] = [
                'count' => count($messages),
                'status_counts' => $statusCounts,
                'recent' => array_slice(array_map(static function (array $message): array {
                    return [
                        'message_id' => (string) ($message['message_id'] ?? ''),
                        'message_key' => (string) ($message['message_key'] ?? ''),
                        'status' => (string) ($message['status'] ?? ''),
                        'reason' => (string) ($message['reason'] ?? ''),
                        'subject' => (string) ($message['subject'] ?? ''),
                        'recorded_at' => (string) ($message['recorded_at'] ?? ''),
                    ];
                }, $messages), 0, 10),
            ];
            $total += count($messages);
        }

        return [
            'state_file' => $this->stateFile,
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'mailboxes' => $mailboxes,
            'total_records' => $total,
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
}


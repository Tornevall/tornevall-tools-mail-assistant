<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class HistoryHiddenFakeToolsApiClient extends ToolsApiClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        return ['response' => 'stub'];
    }

    public function sendReplyViaTools(array $payload): array
    {
        return ['ok' => true];
    }
}

final class HistoryHiddenFakeImapMailboxClient extends ImapMailboxClient
{
    private array $messages;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function fetchUnseenMessages(int $limit = 20): array
    {
        return array_slice($this->messages, 0, $limit);
    }

    public function markSeen(int $uid): bool
    {
        return true;
    }

    public function moveMessage(int $uid, string $folder): bool
    {
        return true;
    }

    public function deleteMessage(int $uid): bool
    {
        return true;
    }
}

final class HistoryHiddenRunner extends MailAssistantRunner
{
    private ImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $state, ImapMailboxClient $imap)
    {
        parent::__construct($tools, $logger, $state);
        $this->imap = $imap;
    }

    protected function createImapMailboxClient(array $config): ImapMailboxClient
    {
        return $this->imap;
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'mailboxes' => [[
        'id' => 9,
        'name' => 'History default hidden mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'footer' => 'Kind regards',
            'run_limit' => 20,
        ],
        'rules' => [[
            'id' => 7,
            'name' => 'Static rule',
            'sort_order' => 0,
            'match' => [
                'from_contains' => 'sender@example.test',
                'to_contains' => '',
                'subject_contains' => '',
                'body_contains' => '',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => false,
                'subject_prefix' => 'Re:',
                'from_name' => 'Support',
                'from_email' => 'support@example.test',
                'bcc' => '',
                'template_text' => 'Thanks for your message.',
                'footer_mode' => 'static',
                'footer_text' => 'Kind regards',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 501,
    'is_seen' => false,
    'message_id' => '<history-hidden@example.test>',
    'message_key' => '<history-hidden@example.test>',
    'subject' => 'Hello there',
    'subject_normalized' => 'Hello there',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Sat, 19 Apr 2026 12:00:00 +0000',
    'body_text' => 'Just testing.',
    'body_text_reply_aware' => 'Just testing.',
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(sys_get_temp_dir() . '/mail-assistant-history-hidden.log', sys_get_temp_dir() . '/mail-assistant-history-hidden-last-run.json');
$state = new MessageStateStore(sys_get_temp_dir() . '/mail-assistant-history-hidden-state.json');
$state->remember(9, '<history-hidden@example.test>', [
    'message_id' => '<history-hidden@example.test>',
    'status' => 'handled',
    'reason' => 'rule_matched_replied',
    'subject' => 'Hello there',
]);

$runner = new HistoryHiddenRunner(
    new HistoryHiddenFakeToolsApiClient($config),
    $logger,
    $state,
    new HistoryHiddenFakeImapMailboxClient([$message])
);

$summary = $runner->run(['dry_run' => true]);

assertSameValue(1, $summary['messages_handled'] ?? null, 'Unread mail should still be processed even if local history already has a handled record.');
assertTrueValue(!array_key_exists('message_state', $summary), 'History diagnostics should be hidden by default unless explicitly requested.');
assertTrueValue(!array_key_exists('message_state_records', $summary['mailboxes'][0] ?? []), 'Mailbox history records should be hidden by default unless explicitly requested.');
assertSameValue('rule_matched_replied', $summary['mailboxes'][0]['message_results'][0]['reason'] ?? null, 'Current-run result summary should still show the handled outcome.');

echo "history-default-hidden-regression: ok\n";


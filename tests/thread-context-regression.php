<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ThreadContextToolsApiClient extends ToolsApiClient
{
    private array $config;
    public array $lastThreadContext = [];

    public function __construct(array $config)
    {
        parent::__construct('https://example.invalid/api', 'test-token');
        $this->config = $config;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        $this->lastThreadContext = (array) ($message['thread_context'] ?? []);

        return ['response' => 'Thanks, continuing the conversation.'];
    }
}

final class ThreadContextImapMailboxClient extends ImapMailboxClient
{
    private array $messages;

    public function __construct(array $messages)
    {
        parent::__construct([]);
        $this->messages = $messages;
    }

    public function fetchUnseenMessages(int $limit = 20): array
    {
        return array_slice($this->messages, 0, max(1, $limit));
    }

    public function markSeen(int $uid): bool
    {
        return true;
    }
}

final class ThreadContextRunner extends MailAssistantRunner
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

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'mailboxes' => [[
        'id' => 21,
        'name' => 'Thread mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'footer' => 'Kind regards',
            'run_limit' => 20,
        ],
        'rules' => [[
            'id' => 2,
            'name' => 'Thread AI rule',
            'sort_order' => 0,
            'match' => [
                'from_contains' => 'sender@example.test',
                'to_contains' => '',
                'subject_contains' => 'Invoice follow-up',
                'body_contains' => '',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => true,
                'subject_prefix' => 'Re:',
                'from_name' => 'Support',
                'from_email' => 'support@example.test',
                'bcc' => '',
                'template_text' => '',
                'footer_mode' => 'static',
                'footer_text' => 'Kind regards',
                'custom_instruction' => 'Continue the same conversation thread naturally.',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 808,
    'is_seen' => false,
    'message_id' => '<thread-current@example.test>',
    'message_key' => '<thread-current@example.test>',
    'in_reply_to' => '<thread-root@example.test>',
    'references' => ['<thread-root@example.test>'],
    'subject' => 'Re: Invoice follow-up',
    'subject_normalized' => 'Invoice follow-up',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Sat, 19 Apr 2026 13:00:00 +0000',
    'body_text' => 'Checking in again about the invoice.',
    'body_text_reply_aware' => 'Checking in again about the invoice.',
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(sys_get_temp_dir() . '/mail-assistant-thread-context.log', sys_get_temp_dir() . '/mail-assistant-thread-context-last-run.json');
$state = new MessageStateStore(sys_get_temp_dir() . '/mail-assistant-thread-context-state.json');
$state->remember(21, '<thread-root@example.test>', [
    'message_id' => '<thread-root@example.test>',
    'thread_key' => '<thread-root@example.test>',
    'status' => 'handled',
    'reason' => 'rule_matched_replied',
    'subject' => 'Invoice follow-up',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'body_excerpt' => 'Original invoice question.',
    'reply_excerpt' => 'Earlier assistant reply about the invoice.',
]);

$tools = new ThreadContextToolsApiClient($config);
$runner = new ThreadContextRunner($tools, $logger, $state, new ThreadContextImapMailboxClient([$message]));
$result = $runner->run(['dry_run' => true]);

assertTrueValue(($result['messages_handled'] ?? 0) === 1, 'Thread context run should still handle the message.');
assertTrueValue(!empty($tools->lastThreadContext['messages']), 'AI input should receive local thread context messages.');
assertTrueValue((string) ($tools->lastThreadContext['messages'][0]['reply_excerpt'] ?? '') === 'Earlier assistant reply about the invoice.', 'Thread context should include prior local reply summary.');

echo "thread-context-regression: ok\n";


<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class AssistantSentMarkerToolsApiClient extends ToolsApiClient
{
    private array $config;

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
        throw new RuntimeException('generateAiReply() should not run for assistant-marked messages.');
    }
}

final class AssistantSentMarkerImapMailboxClient extends ImapMailboxClient
{
    private array $messages;
    public array $markSeenCalls = [];

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
        $this->markSeenCalls[] = $uid;

        return true;
    }
}

final class AssistantSentMarkerRunner extends MailAssistantRunner
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
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$config = [
    'mailboxes' => [[
        'id' => 44,
        'name' => 'Assistant marker mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'mark_seen_on_skip' => false,
        ],
        'rules' => [[
            'id' => 441,
            'name' => 'Would reply otherwise',
            'sort_order' => 0,
            'match' => [
                'from_contains' => 'support@example.test',
                'to_contains' => '',
                'subject_contains' => 'Re:',
                'body_contains' => '',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => false,
                'template_text' => 'This should never be sent.',
                'subject_prefix' => 'Re:',
                'from_name' => 'Support',
                'from_email' => 'support@example.test',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 1001,
    'is_seen' => false,
    'message_id' => '<assistant-sent@example.test>',
    'message_key' => '<assistant-sent@example.test>',
    'subject' => 'Re: Your ticket',
    'subject_normalized' => 'Your ticket',
    'from' => 'support@example.test',
    'to' => 'user@example.test',
    'date' => 'Sat, 19 Apr 2026 19:00:00 +0000',
    'body_text' => 'Sent by assistant.',
    'body_text_reply_aware' => 'Sent by assistant.',
    'headers_map' => [
        'x-tornevall-mail-assistant' => 'sent',
    ],
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(sys_get_temp_dir() . '/mail-assistant-sent-marker.log', sys_get_temp_dir() . '/mail-assistant-sent-marker-last-run.json');
$state = new MessageStateStore(sys_get_temp_dir() . '/mail-assistant-sent-marker-state.json');
$imap = new AssistantSentMarkerImapMailboxClient([$message]);
$runner = new AssistantSentMarkerRunner(new AssistantSentMarkerToolsApiClient($config), $logger, $state, $imap);

$result = $runner->run(['include_history' => true]);

assertSameValue(1, $result['messages_skipped'] ?? null, 'Assistant-marked messages should be skipped.');
assertSameValue(1, $result['messages_assistant_sent_skipped'] ?? null, 'Assistant marker skip counter should increment.');
assertSameValue([1001], $imap->markSeenCalls, 'Assistant-marked messages should be marked seen to prevent loop retries.');
assertSameValue(
    'assistant_sent_marker',
    $result['mailboxes'][0]['message_state_records'][0]['reason'] ?? null,
    'Assistant-marked skip reason should be recorded.'
);

fwrite(STDOUT, "assistant-sent-marker-regression: ok\n");


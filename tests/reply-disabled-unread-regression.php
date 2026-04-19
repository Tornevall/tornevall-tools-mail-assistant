<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ReplyDisabledToolsApiClient extends ToolsApiClient
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
}

final class ReplyDisabledImapMailboxClient extends ImapMailboxClient
{
    private array $messages;
    public array $markSeenCalls = [];
    public array $markUnseenCalls = [];
    public array $moveCalls = [];
    public array $deleteCalls = [];

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

    public function markUnseen(int $uid): bool
    {
        $this->markUnseenCalls[] = $uid;
        return true;
    }

    public function moveMessage(int $uid, string $folder): bool
    {
        $this->moveCalls[] = [$uid, $folder];
        return true;
    }

    public function deleteMessage(int $uid): bool
    {
        $this->deleteCalls[] = $uid;
        return true;
    }
}

final class ReplyDisabledRunner extends MailAssistantRunner
{
    private ReplyDisabledImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $state, ReplyDisabledImapMailboxClient $imap)
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

function makeTempPath(string $prefix, string $suffix): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true) . $suffix;
}

$config = [
    'mailboxes' => [[
        'id' => 11,
        'name' => 'Reply-disabled mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'mark_seen_on_skip' => true,
        ],
        'rules' => [[
            'id' => 7,
            'name' => 'Match only, no reply',
            'sort_order' => 0,
            'match' => [
                'from_contains' => 'sender@example.test',
                'to_contains' => '',
                'subject_contains' => '',
                'body_contains' => '',
            ],
            'reply' => [
                'enabled' => false,
                'ai_enabled' => false,
            ],
            'post_handle' => [
                'move_to_folder' => 'Handled',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 707,
    'is_seen' => false,
    'message_id' => '<reply-disabled@example.test>',
    'message_key' => '<reply-disabled@example.test>',
    'subject' => 'Please do not hide this',
    'subject_normalized' => 'Please do not hide this',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Sat, 19 Apr 2026 09:00:00 +0000',
    'body_text' => 'This should match a rule with reply disabled.',
    'body_text_reply_aware' => 'This should match a rule with reply disabled.',
    'spam_assassin' => ['present' => false],
];

$imap = new ReplyDisabledImapMailboxClient([$message]);
$runner = new ReplyDisabledRunner(
    new ReplyDisabledToolsApiClient($config),
    new Logger(makeTempPath('mail-assistant-reply-disabled-log', '.log'), makeTempPath('mail-assistant-reply-disabled-last-run', '.json')),
    new MessageStateStore(makeTempPath('mail-assistant-reply-disabled-state', '.json')),
    $imap
);

$result = $runner->run();
$messageResult = $result['mailboxes'][0]['message_results'][0] ?? null;
if (!is_array($messageResult)) {
    throw new RuntimeException('Expected one message result row for the reply-disabled case.');
}

assertSameValue(1, $result['messages_skipped'] ?? null, 'Reply-disabled matches should now stay in skipped state by default.');
assertSameValue(0, $result['messages_handled'] ?? null, 'Reply-disabled matches should not be reported as handled when no reply was sent.');
assertSameValue([], $imap->markSeenCalls, 'Reply-disabled matches must stay unread by default.');
assertSameValue([707], $imap->markUnseenCalls, 'Reply-disabled matches should explicitly be forced back to unread when supported.');
assertSameValue([], $imap->moveCalls, 'Reply-disabled matches must not be moved by default.');
assertSameValue([], $imap->deleteCalls, 'Reply-disabled matches must not be deleted by default.');
assertSameValue('rule_matched_reply_not_sent', $messageResult['reason'] ?? null, 'Reply-disabled matches should expose the explicit unread-preservation reason.');

fwrite(STDOUT, "reply-disabled-unread-regression: ok\n");


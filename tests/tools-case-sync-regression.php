<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class CaseSyncToolsApiClient extends ToolsApiClient
{
    private array $config;
    public array $syncCalls = [];

    public function __construct(array $config)
    {
        parent::__construct('https://example.invalid/api', 'test-token');
        $this->config = $config;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }

    public function syncSupportCase(array $payload): array
    {
        $this->syncCalls[] = $payload;

        return [
            'id' => 91,
            'mailbox_id' => (int) ($payload['mailbox_id'] ?? 0),
            'reply_issue_id' => (string) ($payload['reply_issue_id'] ?? ''),
            'admin_url' => 'https://example.invalid/admin/mail-support-assistant/cases/91',
            'public_url' => 'https://example.invalid/support/case/test-token',
        ];
    }
}

final class CaseSyncImapMailboxClient extends ImapMailboxClient
{
    private array $messages;
    public array $markUnseenCalls = [];

    public function __construct(array $messages)
    {
        parent::__construct([]);
        $this->messages = $messages;
    }

    public function fetchUnseenMessages(int $limit = 20): array
    {
        return array_slice($this->messages, 0, max(1, $limit));
    }

    public function markUnseen(int $uid): bool
    {
        $this->markUnseenCalls[] = $uid;

        return true;
    }
}

final class CaseSyncRunner extends MailAssistantRunner
{
    private CaseSyncImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, CaseSyncImapMailboxClient $imap)
    {
        parent::__construct($tools, $logger, $messageState);
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

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function makeTempPath(string $prefix, string $suffix): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);

    return $base . $suffix;
}

$config = [
    'mailboxes' => [[
        'id' => 77,
        'name' => 'Case sync mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 10,
            'generic_no_match_ai_enabled' => false,
            'generic_no_match_rules' => [],
        ],
        'rules' => [],
    ]],
];

$message = [[
    'uid' => 1701,
    'message_no' => 1,
    'is_seen' => false,
    'message_id' => 'case-sync@example.test',
    'message_key' => 'case-sync@example.test',
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Need help with a broken feed import',
    'subject_normalized' => 'Need help with a broken feed import',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Mon, 21 Apr 2026 10:15:00 +0000',
    'headers_raw' => '',
    'headers_map' => [],
    'body_text_raw' => 'The feed import has stopped working.',
    'body_text' => 'The feed import has stopped working.',
    'body_text_reply_aware' => 'The feed import has stopped working.',
    'body_html' => '',
    'spam_assassin' => [],
    'spam_assassin_wrapper_removed' => false,
]];

$tools = new CaseSyncToolsApiClient($config);
$imap = new CaseSyncImapMailboxClient($message);
$logger = new Logger(makeTempPath('case-sync-log', '.log'), makeTempPath('case-sync-last-run', '.json'));
$messageState = new MessageStateStore(makeTempPath('case-sync-state', '.json'));
$runner = new CaseSyncRunner($tools, $logger, $messageState, $imap);

$result = $runner->run();

assertTrueValue(!empty($result['ok']), 'Runner should complete successfully.');
assertSameValue(1, (int) ($result['messages_skipped'] ?? 0), 'Message should remain unanswered/skipped.');
assertSameValue(2, count($tools->syncCalls), 'Unread mail should be synced once on discovery and once again with the final unanswered outcome.');
assertSameValue(77, (int) ($tools->syncCalls[0]['mailbox_id'] ?? 0), 'Case sync payload should contain the mailbox id.');
assertSameValue('case-sync@example.test', (string) ($tools->syncCalls[0]['message_id'] ?? ''), 'Case sync payload should contain the inbound message id.');
assertSameValue('recorded', (string) ($tools->syncCalls[0]['status'] ?? ''), 'The first sync should report that the unread mailbox message was discovered.');
assertSameValue('unread_message_discovered', (string) ($tools->syncCalls[0]['reason'] ?? ''), 'The first sync should use the unread discovery reason code.');
assertSameValue('ignored', (string) ($tools->syncCalls[1]['status'] ?? ''), 'The second sync should carry the final unanswered message status.');
assertSameValue('no_matching_rule_generic_ai_disabled', (string) ($tools->syncCalls[1]['reason'] ?? ''), 'The second sync should keep the final unanswered reason code.');
assertSameValue('The feed import has stopped working.', (string) ($tools->syncCalls[0]['body_text'] ?? ''), 'Case sync payload should now include the full plain-text inbound body.');
assertSameValue('The feed import has stopped working.', (string) ($tools->syncCalls[0]['body_text_reply_aware'] ?? ''), 'Case sync payload should include the reply-aware inbound body too.');
assertTrueValue(!empty(($tools->syncCalls[0]['meta']['source_instance'] ?? '')), 'Case sync payload should include centralized source-instance metadata.');

echo "tools-case-sync-regression: OK\n";


<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ToolsFollowUpMarkSeenToolsApiClient extends ToolsApiClient
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

    public function syncSupportCase(array $payload): array
    {
        return [
            'id' => 91,
            'status' => 'needs_attention',
            'admin_url' => 'https://example.invalid/admin/mail-support-assistant/cases/91',
            'public_url' => 'https://example.invalid/support/case/example',
            'subject' => (string) ($payload['subject'] ?? ''),
        ];
    }
}

final class ToolsFollowUpMarkSeenImapMailboxClient extends ImapMailboxClient
{
    private array $messages;
    public array $markSeenCalls = [];
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
}

final class ToolsFollowUpMarkSeenRunner extends MailAssistantRunner
{
    private ToolsFollowUpMarkSeenImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $state, ToolsFollowUpMarkSeenImapMailboxClient $imap)
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
        'id' => 5,
        'name' => 'Tools follow-up mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'mark_seen_on_skip' => false,
            'generic_no_match_ai_enabled' => false,
        ],
        'rules' => [],
    ]],
];

$message = [
    'uid' => 808,
    'is_seen' => false,
    'message_id' => '<tools-followup@example.test>',
    'message_key' => '<tools-followup@example.test>',
    'subject' => 'Need manual follow-up in Tools',
    'subject_normalized' => 'Need manual follow-up in Tools',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Sat, 09 May 2026 10:00:00 +0000',
    'body_text' => 'No rule matches this message, but it should be handed over to Tools.',
    'body_text_reply_aware' => 'No rule matches this message, but it should be handed over to Tools.',
    'spam_assassin' => ['present' => false],
];

$imap = new ToolsFollowUpMarkSeenImapMailboxClient([$message]);
$runner = new ToolsFollowUpMarkSeenRunner(
    new ToolsFollowUpMarkSeenToolsApiClient($config),
    new Logger(makeTempPath('mail-assistant-tools-followup-log', '.log'), makeTempPath('mail-assistant-tools-followup-last-run', '.json')),
    new MessageStateStore(makeTempPath('mail-assistant-tools-followup-state', '.json')),
    $imap
);

$result = $runner->run();
$messageResult = $result['mailboxes'][0]['message_results'][0] ?? null;
if (!is_array($messageResult)) {
    throw new RuntimeException('Expected one message result row for the Tools follow-up handoff case.');
}

assertSameValue(1, $result['messages_skipped'] ?? null, 'Tools follow-up handoff should still be counted as skipped locally.');
assertSameValue([808], $imap->markSeenCalls, 'Messages handed over to Tools as needs_attention should be marked seen in IMAP.');
assertSameValue([], $imap->markUnseenCalls, 'Successful Tools follow-up handoff should not force the message back to unread.');
assertSameValue('no_matching_rule_generic_ai_disabled', $messageResult['reason'] ?? null, 'The local reason should still explain that no generic fallback handled the message.');

fwrite(STDOUT, "tools-followup-mark-seen-regression: ok\n");


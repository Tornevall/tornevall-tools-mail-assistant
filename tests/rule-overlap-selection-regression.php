<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class RuleOverlapFakeToolsApiClient extends ToolsApiClient
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

final class RuleOverlapFakeImapMailboxClient extends ImapMailboxClient
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

final class RuleOverlapTestRunner extends MailAssistantRunner
{
    private RuleOverlapFakeImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $state, RuleOverlapFakeImapMailboxClient $imap)
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

function assertTrueValue(bool $actual, string $message): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function makeTempPath(string $prefix, string $suffix): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true) . $suffix;
}

$config = [
    'mailboxes' => [[
        'id' => 9,
        'name' => 'Overlap mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'mark_seen_on_skip' => false,
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'footer' => 'Kind regards',
        ],
        'rules' => [
            [
                'id' => 1,
                'name' => 'Generic Gmail rule',
                'sort_order' => 50,
                'match' => [
                    'from_contains' => 'gmail.com',
                    'to_contains' => '',
                    'subject_contains' => '',
                    'body_contains' => '',
                ],
                'reply' => [
                    'enabled' => true,
                    'ai_enabled' => false,
                    'template_text' => 'Generic Gmail reply',
                    'footer_mode' => 'none',
                ],
                'post_handle' => [],
            ],
            [
                'id' => 2,
                'name' => 'Copyright notice rule',
                'sort_order' => 0,
                'match' => [
                    'from_contains' => '',
                    'to_contains' => '',
                    'subject_contains' => 'copyright',
                    'body_contains' => 'abuse@support@tornevall.net',
                ],
                'reply' => [
                    'enabled' => true,
                    'ai_enabled' => false,
                    'template_text' => 'Specific copyright reply',
                    'footer_mode' => 'none',
                ],
                'post_handle' => [],
            ],
        ],
    ]],
];

$message = [
    'uid' => 300,
    'is_seen' => false,
    'message_id' => '<overlap@example.test>',
    'message_key' => '<overlap@example.test>',
    'subject' => 'copyright-test from gmail',
    'subject_normalized' => 'copyright-test from gmail',
    'from' => 'Example Sender <sender@gmail.com>',
    'to' => 'abuse@support@tornevall.net',
    'date' => 'Fri, 18 Apr 2026 12:00:00 +0000',
    'body_text' => 'Please contact abuse@support@tornevall.net about this copyright issue.',
    'body_text_reply_aware' => 'Please contact abuse@support@tornevall.net about this copyright issue.',
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(makeTempPath('mail-assistant-overlap-log', '.log'), makeTempPath('mail-assistant-overlap-last-run', '.json'));
$state = new MessageStateStore(makeTempPath('mail-assistant-overlap-state', '.json'));
$runner = new RuleOverlapTestRunner(new RuleOverlapFakeToolsApiClient($config), $logger, $state, new RuleOverlapFakeImapMailboxClient([$message]));
$result = $runner->run(['dry_run' => true, 'include_history' => true]);

$record = $result['mailboxes'][0]['message_state_records'][0] ?? null;
if (!is_array($record)) {
    throw new RuntimeException('Expected one message-state record for overlap diagnostics.');
}

assertSameValue(2, $record['matching_rule_count'] ?? null, 'Both overlapping rules should be recorded as matches.');
assertSameValue('Copyright notice rule', $record['selected_rule']['name'] ?? null, 'The higher-priority rule should win when sort_order says it is more important.');
assertTrueValue(count((array) ($record['matching_rules'] ?? [])) === 2, 'Matching-rule diagnostics should list both candidates.');
assertSameValue('Generic Gmail rule', $record['matching_rules'][1]['name'] ?? null, 'The lower-specificity Gmail rule should still be visible as a secondary candidate.');

fwrite(STDOUT, "rule-overlap-selection-regression: ok\n");


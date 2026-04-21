<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class QuotaAlertToolsApiClient extends ToolsApiClient
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

    public function evaluateGenericNoMatchReply(array $mailbox, array $message, array $options = []): array
    {
        throw new RuntimeException('AI request failed (models tried: gpt-5.4 -> gpt-4o-mini): You exceeded your current quota, please check your plan and billing details.');
    }
}

final class QuotaAlertImapMailboxClient extends ImapMailboxClient
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

final class QuotaAlertRunner extends MailAssistantRunner
{
    private QuotaAlertImapMailboxClient $imap;
    public array $sentAlerts = [];

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, QuotaAlertImapMailboxClient $imap)
    {
        parent::__construct($tools, $logger, $messageState);
        $this->imap = $imap;
    }

    protected function createImapMailboxClient(array $config): ImapMailboxClient
    {
        return $this->imap;
    }

    protected function dispatchQuotaAlertMail(array $mailbox, array $alert): void
    {
        $this->sentAlerts[] = [
            'mailbox' => $mailbox,
            'alert' => $alert,
        ];
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeTempPath(string $prefix, string $suffix): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);

    return $base . $suffix;
}

$config = [
    'mailboxes' => [[
        'id' => 61,
        'name' => 'Quota mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_rules' => [[
                'id' => 7,
                'sort_order' => 0,
                'is_active' => true,
                'if' => 'If this is a normal support request, we may answer.',
                'instruction' => 'Answer politely.',
            ]],
        ],
        'rules' => [],
    ]],
];

$message = [
    'uid' => 9002,
    'is_seen' => false,
    'message_id' => '<quota@example.test>',
    'message_key' => '<quota@example.test>',
    'subject' => 'Possible collaboration?',
    'subject_normalized' => 'Possible collaboration?',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Mon, 21 Apr 2026 12:11:44 +0000',
    'body_text' => 'Hello, would you like to collaborate?',
    'body_text_reply_aware' => 'Hello, would you like to collaborate?',
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json'));
$messageState = new MessageStateStore(makeTempPath('mail-assistant-state', '.json'));
$imap = new QuotaAlertImapMailboxClient([$message]);
$tools = new QuotaAlertToolsApiClient($config);
$runner = new QuotaAlertRunner($tools, $logger, $messageState, $imap);
$summary = $runner->run();

assertSameValue(1, count((array) ($summary['quota_alerts'] ?? [])), 'Quota failure should be promoted into a visible runtime quota alert.');
assertSameValue(1, count($runner->sentAlerts), 'Quota failure should trigger the operator alert dispatch path exactly once.');
assertSameValue([9002], $imap->markSeenCalls, 'Terminal unmatched quota failures should mark the message seen to stop endless retries.');
assertSameValue([], $imap->markUnseenCalls, 'Terminal unmatched quota failures should not restore unread state.');
assertSameValue('no_matching_rule_generic_ai_error', $summary['mailboxes'][0]['message_results'][0]['reason'] ?? null, 'Quota failure should still keep the strict unmatched error reason in diagnostics.');
assertTrueValue(strpos((string) ($summary['quota_alerts'][0]['error'] ?? ''), 'exceeded your current quota') !== false, 'Quota alert should preserve the upstream billing/quota message for operators.');

fwrite(STDOUT, "quota-alert-regression: ok\n");


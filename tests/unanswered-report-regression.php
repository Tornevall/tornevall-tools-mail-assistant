<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class UnansweredReportToolsApiClient extends ToolsApiClient
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
            'id' => 44,
            'mailbox_id' => (int) ($payload['mailbox_id'] ?? 0),
            'admin_url' => 'https://example.invalid/admin/mail-support-assistant/cases/44',
            'public_url' => 'https://example.invalid/support/case/public-token',
        ];
    }
}

final class UnansweredReportImapMailboxClient extends ImapMailboxClient
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

    public function markUnseen(int $uid): bool
    {
        return true;
    }
}

final class UnansweredReportRunner extends MailAssistantRunner
{
    private UnansweredReportImapMailboxClient $imap;
    public array $reports = [];

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, UnansweredReportImapMailboxClient $imap)
    {
        parent::__construct($tools, $logger, $messageState);
        $this->imap = $imap;
    }

    protected function createImapMailboxClient(array $config): ImapMailboxClient
    {
        return $this->imap;
    }

    protected function dispatchUnansweredReportMail(array $report): void
    {
        $this->reports[] = $report;
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

putenv('MAIL_ASSISTANT_UNANSWERED_REPORT_ENABLED=true');

$config = [
    'mailboxes' => [[
        'id' => 88,
        'name' => 'Unanswered report mailbox',
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
    'uid' => 2450,
    'message_no' => 1,
    'is_seen' => false,
    'message_id' => 'report@example.test',
    'message_key' => 'report@example.test',
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Still waiting for help',
    'subject_normalized' => 'Still waiting for help',
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'date' => 'Mon, 21 Apr 2026 11:30:00 +0000',
    'headers_raw' => '',
    'headers_map' => [],
    'body_text_raw' => 'Please get back to me.',
    'body_text' => 'Please get back to me.',
    'body_text_reply_aware' => 'Please get back to me.',
    'body_html' => '',
    'spam_assassin' => [],
    'spam_assassin_wrapper_removed' => false,
]];

$tools = new UnansweredReportToolsApiClient($config);
$imap = new UnansweredReportImapMailboxClient($message);
$logger = new Logger(makeTempPath('unanswered-report-log', '.log'), makeTempPath('unanswered-report-last-run', '.json'));
$messageState = new MessageStateStore(makeTempPath('unanswered-report-state', '.json'));
$runner = new UnansweredReportRunner($tools, $logger, $messageState, $imap);

$result = $runner->run();

assertTrueValue(!empty($result['ok']), 'Runner should complete successfully.');
assertTrueValue(count($runner->reports) === 1, 'One unanswered report should be dispatched.');
assertSameValue(1, (int) ($runner->reports[0]['count'] ?? 0), 'The unanswered report should contain one pending item.');
assertTrueValue(!empty($result['unanswered_report']['sent']), 'Run summary should record the unanswered report as sent.');

putenv('MAIL_ASSISTANT_UNANSWERED_REPORT_ENABLED');

echo "unanswered-report-regression: OK\n";


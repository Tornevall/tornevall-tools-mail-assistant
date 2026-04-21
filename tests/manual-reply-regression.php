<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ManualReplyToolsApiClient extends ToolsApiClient
{
    public array $relayPayloads = [];

    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function sendReplyViaTools(array $payload): array
    {
        $this->relayPayloads[] = $payload;

        return [
            'ok' => true,
            'message' => 'Relay accepted.',
        ];
    }
}

final class ManualReplyImapMailboxClient extends ImapMailboxClient
{
    public array $markSeenCalls = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function fetchUnseenMessages(int $limit = 20): array
    {
        return [];
    }

    public function markSeen(int $uid): bool
    {
        $this->markSeenCalls[] = $uid;

        return true;
    }
}

final class ManualReplyRunner extends MailAssistantRunner
{
    private ManualReplyImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, ManualReplyImapMailboxClient $imap)
    {
        parent::__construct($tools, $logger, $messageState);
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

putenv('MAIL_ASSISTANT_MAIL_TRANSPORT=tools_api');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=0');
putenv('MAIL_ASSISTANT_TOOLS_MAIL_TOKEN=test-mail-token');

$mailbox = [
    'id' => 17,
    'name' => 'Manual reply mailbox',
    'imap' => [],
    'defaults' => [
        'from_name' => 'Support Team',
        'from_email' => 'support@example.test',
        'footer' => 'Kind regards',
    ],
    'rules' => [],
];

$rule = [
    'id' => 88,
    'name' => 'Manual operator assignment',
    'sort_order' => 10,
    'reply' => [
        'subject_prefix' => 'Re:',
        'from_name' => 'Support Team',
        'from_email' => 'support@example.test',
        'bcc' => 'audit@example.test',
        'custom_instruction' => '',
    ],
];

$message = [
    'uid' => 5150,
    'message_id' => '<manual-reply@example.test>',
    'message_key' => '<manual-reply@example.test>',
    'subject' => 'Need invoice help',
    'subject_normalized' => 'Need invoice help',
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'date' => 'Mon, 21 Apr 2026 13:00:00 +0000',
    'body_text' => 'Hello, I need help with invoice 123.',
    'body_text_reply_aware' => 'Hello, I need help with invoice 123.',
    'references' => ['<thread-root@example.test>'],
    'in_reply_to' => '<thread-root@example.test>',
];

$logger = new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json'));
$messageState = new MessageStateStore(makeTempPath('mail-assistant-state', '.json'));
$imap = new ManualReplyImapMailboxClient();
$tools = new ManualReplyToolsApiClient();
$runner = new ManualReplyRunner($tools, $logger, $messageState, $imap);

$result = $runner->sendManualReply(
    $mailbox,
    $message,
    $rule,
    "Thanks for your message. Please send the invoice number from the PDF copy so support can continue."
);

$record = $messageState->getRecord(17, '<manual-reply@example.test>');
assertSameValue(true, $result['ok'] ?? null, 'Manual reply should succeed.');
assertSameValue('manual_reply_sent', $result['reason'] ?? null, 'Manual reply should report the manual_reply_sent reason.');
assertSameValue([5150], $imap->markSeenCalls, 'Manual reply should mark the message seen after send.');
assertSameValue(1, count($tools->relayPayloads), 'Manual reply should use the normal reply transport pipeline exactly once.');
assertSameValue('manual_operator_rule_assignment', $record['rule_resolution_source'] ?? null, 'Manual reply should persist the selected rule assignment in local state.');
assertSameValue(88, $record['selected_rule']['id'] ?? null, 'Manual reply should persist the assigned rule id.');
assertSameValue('tools_api', $record['reply_transport'] ?? null, 'Manual reply should persist the reply transport.');
assertTrueValue(strpos((string) ($tools->relayPayloads[0]['body_html'] ?? ''), 'Summary of your request') !== false, 'Manual reply HTML should reuse the same styled reply wrapper and request summary block as automatic replies.');

fwrite(STDOUT, "manual-reply-regression: ok\n");

putenv('MAIL_ASSISTANT_MAIL_TRANSPORT');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API');
putenv('MAIL_ASSISTANT_TOOLS_MAIL_TOKEN');


<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ReplyChainReplyMessageIdToolsApiClient extends ToolsApiClient
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
        return ['response' => 'Absolutely — let us continue the same repository support thread.'];
    }
}

final class ReplyChainReplyMessageIdImapMailboxClient extends ImapMailboxClient
{
    private array $messages = [];

    public function __construct(array $messages = [])
    {
        parent::__construct([]);
        $this->messages = $messages;
    }

    public function setMessages(array $messages): void
    {
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

final class ReplyChainReplyMessageIdRunner extends MailAssistantRunner
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
        'id' => 47,
        'name' => 'Reply message id mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'footer' => 'Kind regards',
            'run_limit' => 10,
        ],
        'rules' => [[
            'id' => 9,
            'name' => 'Repository support rule',
            'sort_order' => 5,
            'match' => [
                'from_contains' => '',
                'to_contains' => '',
                'subject_contains' => 'Repository support',
                'body_contains' => '',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => true,
                'subject_prefix' => 'Re:',
                'from_name' => 'Support',
                'from_email' => 'support@example.test',
                'footer_mode' => 'static',
                'footer_text' => 'Kind regards',
                'custom_instruction' => 'Continue the same repository support thread.',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$initialMessage = [
    'uid' => 4001,
    'is_seen' => false,
    'message_id' => '<initial-repository@example.test>',
    'message_key' => '<initial-repository@example.test>',
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Repository support',
    'subject_normalized' => 'Repository support',
    'from' => 'user@example.test',
    'to' => 'support@example.test',
    'date' => 'Sun, 20 Apr 2026 12:00:00 +0000',
    'body_text' => 'Could you explain how the repository support API works?',
    'body_text_reply_aware' => 'Could you explain how the repository support API works?',
    'spam_assassin' => ['present' => false],
];

$tmp = sys_get_temp_dir() . '/mail-assistant-reply-message-id-' . uniqid('', true);
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/logs', 0777, true);
@mkdir($tmp . '/state', 0777, true);
@mkdir($tmp . '/pickup', 0777, true);

putenv('MAIL_ASSISTANT_MAIL_TRANSPORT=pickup');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS=');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=false');
putenv('MAIL_ASSISTANT_PICKUP_DIR=' . $tmp . '/pickup');

$logger = new Logger($tmp . '/logs/test.log', $tmp . '/last-run.json');
$state = new MessageStateStore($tmp . '/state/message-state.json');
$imap = new ReplyChainReplyMessageIdImapMailboxClient([$initialMessage]);
$tools = new ReplyChainReplyMessageIdToolsApiClient($config);
$runner = new ReplyChainReplyMessageIdRunner($tools, $logger, $state, $imap);

$firstRun = $runner->run(['dry_run' => false]);
$firstResult = (array) (($firstRun['mailboxes'][0]['message_results'][0] ?? []));
$storedRecord = $state->getRecord(47, '<initial-repository@example.test>');
$replyMessageId = is_array($storedRecord) ? (string) ($storedRecord['reply_message_id'] ?? '') : '';

assertTrueValue(($firstRun['messages_handled'] ?? 0) === 1, 'Initial repository message should be handled successfully.');
assertTrueValue($replyMessageId !== '', 'Handled state should keep the generated outgoing reply message id.');
assertTrueValue((string) ($firstResult['reply_message_id'] ?? '') === $replyMessageId, 'Run diagnostics should expose the same generated reply message id.');

$followUpMessage = [
    'uid' => 4002,
    'is_seen' => false,
    'message_id' => '<follow-up-through-reply-id@example.test>',
    'message_key' => '<follow-up-through-reply-id@example.test>',
    'in_reply_to' => '<' . $replyMessageId . '>',
    'references' => ['<' . $replyMessageId . '>'],
    'subject' => 'Re: Thanks again',
    'subject_normalized' => 'Thanks again',
    'from' => 'user@example.test',
    'to' => 'support@example.test',
    'date' => 'Sun, 20 Apr 2026 12:20:00 +0000',
    'body_text' => 'Can you also show a code example?',
    'body_text_reply_aware' => 'Can you also show a code example?',
    'spam_assassin' => ['present' => false],
];

$imap->setMessages([$followUpMessage]);
$secondRun = $runner->run(['dry_run' => true]);
$secondResult = (array) (($secondRun['mailboxes'][0]['message_results'][0] ?? []));

assertTrueValue(($secondRun['messages_handled'] ?? 0) === 1, 'Follow-up message should reuse the earlier selected rule through the stored outgoing reply message id.');
assertTrueValue((string) ($secondResult['rule_resolution_source'] ?? '') === 'thread_history_selected_rule', 'Diagnostics should show explicit reply-chain reuse through stored reply Message-Id metadata.');
assertTrueValue((string) ($secondResult['reused_from_message_id'] ?? '') === '<initial-repository@example.test>', 'The reused record should still point back to the original handled inbound message.');
assertTrueValue((int) (($secondResult['selected_rule']['id'] ?? 0)) === 9, 'The reused rule id should match the earlier handled rule.');

echo "reply-chain-reply-message-id-regression: ok\n";


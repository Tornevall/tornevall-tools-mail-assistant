<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ReplyChainRuleReuseToolsApiClient extends ToolsApiClient
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
        return ['response' => 'Certainly — here is the same support guidance continued for your follow-up question.'];
    }
}

final class ReplyChainRuleReuseImapMailboxClient extends ImapMailboxClient
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

final class ReplyChainRuleReuseRunner extends MailAssistantRunner
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
        'id' => 44,
        'name' => 'Reply chain mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'footer' => 'Kind regards',
            'run_limit' => 10,
        ],
        'rules' => [[
            'id' => 7,
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
                'custom_instruction' => 'Continue helping with the same repository support thread.',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 3001,
    'is_seen' => false,
    'message_id' => '<follow-up@example.test>',
    'message_key' => '<follow-up@example.test>',
    'in_reply_to' => '<initial@example.test>',
    'references' => ['<initial@example.test>'],
    'subject' => 'Re: Thanks again',
    'subject_normalized' => 'Thanks again',
    'from' => 'user@example.test',
    'to' => 'support@example.test',
    'date' => 'Sun, 20 Apr 2026 09:00:00 +0000',
    'body_text' => 'Can you also show a code example?',
    'body_text_reply_aware' => 'Can you also show a code example?',
    'spam_assassin' => ['present' => false],
];

$tmp = sys_get_temp_dir() . '/mail-assistant-reply-chain-rule-' . uniqid('', true);
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/logs', 0777, true);
@mkdir($tmp . '/state', 0777, true);

$logger = new Logger($tmp . '/logs/test.log', $tmp . '/last-run.json');
$state = new MessageStateStore($tmp . '/state/message-state.json');
$state->remember(44, '<initial@example.test>', [
    'message_id' => '<initial@example.test>',
    'thread_key' => '<initial@example.test>',
    'status' => 'handled',
    'reason' => 'rule_matched_replied',
    'subject' => 'Repository support',
    'from' => 'user@example.test',
    'to' => 'support@example.test',
    'selected_rule' => [
        'id' => 7,
        'name' => 'Repository support rule',
        'sort_order' => 5,
    ],
    'body_excerpt' => 'Original repository support request.',
    'reply_excerpt' => 'Earlier repository support reply.',
]);

$tools = new ReplyChainRuleReuseToolsApiClient($config);
$runner = new ReplyChainRuleReuseRunner($tools, $logger, $state, new ReplyChainRuleReuseImapMailboxClient([$message]));
$result = $runner->run(['dry_run' => true]);

$messageResult = (array) (($result['mailboxes'][0]['message_results'][0] ?? []));

assertTrueValue(($result['messages_handled'] ?? 0) === 1, 'Follow-up message should be handled by reusing the earlier matched rule.');
assertTrueValue((string) ($messageResult['rule_resolution_source'] ?? '') === 'thread_history_selected_rule', 'Diagnostics should say the reply-chain reused the earlier selected rule.');
assertTrueValue((string) ($messageResult['reused_from_message_id'] ?? '') === '<initial@example.test>', 'Diagnostics should keep the original thread-linked message id.');
assertTrueValue((int) (($messageResult['selected_rule']['id'] ?? 0)) === 7, 'The reused rule id should match the earlier handled rule.');

echo "reply-chain-rule-reuse-regression: ok\n";


<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ReplyChainSubjectFallbackToolsApiClient extends ToolsApiClient
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
        return ['response' => 'Här fortsätter vi samma supporttråd som tidigare.'];
    }
}

final class ReplyChainSubjectFallbackImapMailboxClient extends ImapMailboxClient
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

final class ReplyChainSubjectFallbackRunner extends MailAssistantRunner
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
        'id' => 46,
        'name' => 'Subject fallback mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'footer' => 'Kind regards',
            'run_limit' => 10,
        ],
        'rules' => [[
            'id' => 8,
            'name' => 'Older support onboarding rule',
            'sort_order' => 10,
            'match' => [
                'from_contains' => '',
                'to_contains' => '',
                'subject_contains' => '',
                'body_contains' => 'registrera konto via tools',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => true,
                'subject_prefix' => 'Re:',
                'from_name' => 'Support',
                'from_email' => 'support@example.test',
                'footer_mode' => 'static',
                'footer_text' => 'Kind regards',
                'custom_instruction' => 'Continue the same onboarding support thread.',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 3003,
    'is_seen' => false,
    'message_id' => '<follow-up-no-headers@example.test>',
    'message_key' => '<follow-up-no-headers@example.test>',
    'in_reply_to' => '',
    'references' => [],
    'subject' => 'Re: hur fungerar support-assistenten?',
    'subject_normalized' => 'hur fungerar support-assistenten?',
    'from' => 'rbapplett@gmail.com',
    'to' => 'support@example.test',
    'date' => 'Sun, 20 Apr 2026 11:30:00 +0000',
    'body_text' => 'Tack, men hur gör jag vidare?',
    'body_text_reply_aware' => 'Tack, men hur gör jag vidare?',
    'spam_assassin' => ['present' => false],
];

$tmp = sys_get_temp_dir() . '/mail-assistant-reply-chain-subject-fallback-' . uniqid('', true);
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/logs', 0777, true);
@mkdir($tmp . '/state', 0777, true);

$logger = new Logger($tmp . '/logs/test.log', $tmp . '/last-run.json');
$state = new MessageStateStore($tmp . '/state/message-state.json');
$state->remember(46, '<older-support-root@example.test>', [
    'message_id' => '<older-support-root@example.test>',
    'thread_key' => '<older-support-root@example.test>',
    'subject_normalized' => 'hur fungerar support-assistenten?',
    'status' => 'handled',
    'reason' => 'rule_matched_replied',
    'subject' => 'hur fungerar support-assistenten?',
    'from' => 'rbapplett@gmail.com',
    'to' => 'support@example.test',
    'selected_rule' => [
        'id' => 8,
        'name' => 'Older support onboarding rule',
        'sort_order' => 10,
    ],
    'body_excerpt' => 'Initial onboarding support request.',
    'reply_excerpt' => 'Earlier onboarding guidance.',
]);

$tools = new ReplyChainSubjectFallbackToolsApiClient($config);
$runner = new ReplyChainSubjectFallbackRunner($tools, $logger, $state, new ReplyChainSubjectFallbackImapMailboxClient([$message]));
$result = $runner->run(['dry_run' => true]);

$messageResult = (array) (($result['mailboxes'][0]['message_results'][0] ?? []));

assertTrueValue(($result['messages_handled'] ?? 0) === 1, 'Header-less follow-up should still be handled through subject/participant continuity fallback.');
assertTrueValue((string) ($messageResult['rule_resolution_source'] ?? '') === 'thread_history_selected_rule_subject_fallback', 'Diagnostics should say the selected rule came from subject/participant fallback continuity.');
assertTrueValue((string) ($messageResult['reused_from_message_id'] ?? '') === '<older-support-root@example.test>', 'Diagnostics should keep the older handled message id even when reply headers are missing.');
assertTrueValue((int) (($messageResult['selected_rule']['id'] ?? 0)) === 8, 'The reused rule id should come from the earlier handled thread state.');

echo "reply-chain-subject-fallback-regression: ok\n";


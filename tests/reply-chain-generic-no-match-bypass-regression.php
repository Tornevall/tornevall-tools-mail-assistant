<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ReplyChainGenericNoMatchBypassToolsApiClient extends ToolsApiClient
{
    private array $config;
    public int $triageCalls = 0;
    public int $continuationCalls = 0;

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
        $this->triageCalls++;

        throw new RuntimeException('Known reply-chain no-match triage should have been bypassed for this explicit thread link.');
    }

    public function generateGenericNoMatchThreadContinuationReply(array $mailbox, array $message, array $options = []): array
    {
        $this->continuationCalls++;

        return [
            'response' => 'Yes — continuing the same repository support thread directly without rerunning the first allow-condition check.',
            'model' => 'gpt-4o-mini',
        ];
    }
}

final class ReplyChainGenericNoMatchBypassImapMailboxClient extends ImapMailboxClient
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

final class ReplyChainGenericNoMatchBypassRunner extends MailAssistantRunner
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
        'id' => 48,
        'name' => 'Known no-match bypass mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_rules' => [
                [
                    'id' => 5,
                    'sort_order' => 20,
                    'is_active' => true,
                    'if' => 'Someone asks about a repository, library, plugin, utility, browser extension, or related code published under either of these sources: https://github.com/Tornevall or https://bitbucket.tornevall.net/',
                    'instruction' => 'Answer briefly and helpfully with repository guidance.',
                ],
                [
                    'id' => 9,
                    'sort_order' => 100,
                    'is_active' => true,
                    'if' => 'If the message is obvious scam, fraud, phishing, or admin abuse.',
                    'instruction' => 'Decline and warn.',
                ],
            ],
        ],
        'rules' => [],
    ]],
];

$message = [
    'uid' => 5001,
    'is_seen' => false,
    'message_id' => '<repo-follow-up-explicit@example.test>',
    'message_key' => '<repo-follow-up-explicit@example.test>',
    'in_reply_to' => '<repo-root-explicit@example.test>',
    'references' => ['<repo-root-explicit@example.test>'],
    'subject' => 'Re: hur fungerar support-assistenten?',
    'subject_normalized' => 'hur fungerar support-assistenten?',
    'from' => 'rbapplett@gmail.com',
    'to' => 'support@example.test',
    'date' => 'Sun, 20 Apr 2026 12:45:00 +0000',
    'body_text' => 'Kan du visa ett exempel på nästa steg?',
    'body_text_reply_aware' => 'Kan du visa ett exempel på nästa steg?',
    'spam_assassin' => ['present' => false],
];

$tmp = sys_get_temp_dir() . '/mail-assistant-reply-chain-no-match-bypass-' . uniqid('', true);
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/logs', 0777, true);
@mkdir($tmp . '/state', 0777, true);

$logger = new Logger($tmp . '/logs/test.log', $tmp . '/last-run.json');
$state = new MessageStateStore($tmp . '/state/message-state.json');
$state->remember(48, '<repo-root-explicit@example.test>', [
    'message_id' => '<repo-root-explicit@example.test>',
    'thread_key' => '<repo-root-explicit@example.test>',
    'status' => 'handled',
    'reason' => 'no_matching_rule_generic_ai_replied',
    'subject' => 'hur fungerar support-assistenten?',
    'from' => 'rbapplett@gmail.com',
    'to' => 'support@example.test',
    'generic_ai_decision' => [
        'matched_no_match_rule_id' => 5,
        'matched_no_match_rule_order' => 20,
        'decision_reason_code' => 'no_matching_rule_generic_ai_replied',
        'reason' => 'Repository support allowed.',
    ],
    'body_excerpt' => 'Initial repository support question.',
    'reply_excerpt' => 'Earlier repository support answer.',
]);

$tools = new ReplyChainGenericNoMatchBypassToolsApiClient($config);
$runner = new ReplyChainGenericNoMatchBypassRunner($tools, $logger, $state, new ReplyChainGenericNoMatchBypassImapMailboxClient([$message]));
$result = $runner->run(['dry_run' => true]);
$messageResult = (array) (($result['mailboxes'][0]['message_results'][0] ?? []));
$decision = (array) ($messageResult['generic_ai_decision'] ?? []);

assertTrueValue(($result['messages_handled'] ?? 0) === 1, 'Explicitly linked generic no-match follow-up should be handled directly.');
assertTrueValue($tools->triageCalls === 0, 'The initial generic no-match allow-condition triage should be bypassed for explicit reply-chain reuse.');
assertTrueValue($tools->continuationCalls === 1, 'The dedicated known-thread continuation reply path should be used once.');
assertTrueValue((string) ($messageResult['rule_resolution_source'] ?? '') === 'thread_history_generic_no_match', 'Diagnostics should still show generic no-match thread reuse.');
assertTrueValue(!empty($decision['bypassed_allow_check']), 'Decision diagnostics should expose that the initial allow-condition check was bypassed.');
assertTrueValue((int) ($decision['matched_no_match_rule_id'] ?? 0) === 5, 'The reused unmatched rule id should still be preserved in diagnostics.');

echo "reply-chain-generic-no-match-bypass-regression: ok\n";


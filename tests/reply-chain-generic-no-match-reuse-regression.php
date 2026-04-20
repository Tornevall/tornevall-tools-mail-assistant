<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class ReplyChainGenericNoMatchReuseToolsApiClient extends ToolsApiClient
{
    private array $config;
    public array $ruleEvaluations = [];

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
        $ifCondition = (string) ($options['if_condition'] ?? '');
        $this->ruleEvaluations[] = $ifCondition;

        if (stripos($ifCondition, 'repository') !== false) {
            return [
                'can_reply' => true,
                'certainty' => 'high',
                'reason' => 'This follow-up belongs to the same repository support thread.',
                'decision_reason_code' => '',
                'risk_flags' => [],
                'raw_response' => '{}',
                'reply' => 'Yes — here is how to keep using the same repository support flow for follow-up questions.',
                'model' => 'gpt-4o-mini',
            ];
        }

        return [
            'can_reply' => false,
            'certainty' => 'high',
            'reason' => 'This is not an admin scam/phishing case.',
            'decision_reason_code' => 'no_matching_rule_generic_ai_rejected',
            'risk_flags' => ['not_admin_match'],
            'raw_response' => '{}',
            'reply' => '',
        ];
    }

    public function sendReplyViaTools(array $payload): array
    {
        return ['ok' => true];
    }
}

final class ReplyChainGenericNoMatchReuseImapMailboxClient extends ImapMailboxClient
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

final class ReplyChainGenericNoMatchReuseRunner extends MailAssistantRunner
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
        'id' => 45,
        'name' => 'Reply chain no-match mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_rules' => [
                [
                    'id' => 4,
                    'sort_order' => 0,
                    'is_active' => true,
                    'if' => 'If the message is obvious scam, fraud, phishing, or admin abuse.',
                    'instruction' => 'Decline and warn.',
                ],
                [
                    'id' => 5,
                    'sort_order' => 20,
                    'is_active' => true,
                    'if' => 'Someone asks about a repository, library, plugin, utility, browser extension, or related code published under either of these sources: https://github.com/Tornevall or https://bitbucket.tornevall.net/',
                    'instruction' => 'Answer briefly and helpfully with repository guidance.',
                ],
            ],
        ],
        'rules' => [],
    ]],
];

$message = [
    'uid' => 3002,
    'is_seen' => false,
    'message_id' => '<repo-follow-up@example.test>',
    'message_key' => '<repo-follow-up@example.test>',
    'in_reply_to' => '<repo-root@example.test>',
    'references' => ['<repo-root@example.test>'],
    'subject' => 'Re: hur fungerar support-assistenten?',
    'subject_normalized' => 'hur fungerar support-assistenten?',
    'from' => 'rbapplett@gmail.com',
    'to' => 'support@example.test',
    'date' => 'Sun, 20 Apr 2026 10:51:00 +0000',
    'body_text' => 'Kan du ge mig ett kodexempel på hur API:et hanteras? Eller vägleda mig på nåt annat sätt?',
    'body_text_reply_aware' => 'Kan du ge mig ett kodexempel på hur API:et hanteras? Eller vägleda mig på nåt annat sätt?',
    'spam_assassin' => [
        'present' => true,
        'flagged' => true,
        'score' => 1.8,
        'tests' => ['HTML_MESSAGE'],
    ],
];

$tmp = sys_get_temp_dir() . '/mail-assistant-reply-chain-no-match-' . uniqid('', true);
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/logs', 0777, true);
@mkdir($tmp . '/state', 0777, true);

$logger = new Logger($tmp . '/logs/test.log', $tmp . '/last-run.json');
$state = new MessageStateStore($tmp . '/state/message-state.json');
$state->remember(45, '<repo-root@example.test>', [
    'message_id' => '<repo-root@example.test>',
    'thread_key' => '<repo-root@example.test>',
    'status' => 'handled',
    'reason' => 'no_matching_rule_generic_ai_replied',
    'subject' => 'hur fungerar support-assistenten?',
    'from' => 'rbapplett@gmail.com',
    'to' => 'support@example.test',
    'generic_ai_decision' => [
        'matched_no_match_rule_id' => 5,
        'matched_no_match_rule_order' => 20,
        'decision_reason_code' => '',
        'reason' => 'Repository support allowed.',
    ],
    'body_excerpt' => 'Initial repository support question.',
    'reply_excerpt' => 'Earlier repository support answer.',
]);

$tools = new ReplyChainGenericNoMatchReuseToolsApiClient($config);
$runner = new ReplyChainGenericNoMatchReuseRunner($tools, $logger, $state, new ReplyChainGenericNoMatchReuseImapMailboxClient([$message]));
$result = $runner->run(['dry_run' => true]);

$messageResult = (array) (($result['mailboxes'][0]['message_results'][0] ?? []));

assertTrueValue(($result['messages_handled'] ?? 0) === 1, 'Reply-chain follow-up should be handled by prioritizing the earlier generic no-match row.');
assertTrueValue(count($tools->ruleEvaluations) >= 1, 'At least one generic no-match row should be evaluated.');
assertTrueValue(stripos((string) ($tools->ruleEvaluations[0] ?? ''), 'repository') !== false, 'The previously used repository no-match row should be evaluated first for the reply chain.');
assertTrueValue((string) ($messageResult['rule_resolution_source'] ?? '') === 'thread_history_generic_no_match', 'Diagnostics should show thread-history generic no-match reuse.');
assertTrueValue((string) ($messageResult['reused_from_message_id'] ?? '') === '<repo-root@example.test>', 'Diagnostics should reference the earlier handled generic no-match message.');
assertTrueValue((int) (($messageResult['generic_ai_decision']['matched_no_match_rule_id'] ?? 0)) === 5, 'The generic AI decision should still point to the reused repository no-match rule id.');

echo "reply-chain-generic-no-match-reuse-regression: ok\n";


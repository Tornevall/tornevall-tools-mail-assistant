<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Tools\ToolsApiClient;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;

final class GenericNoMatchRowsToolsApiClient extends ToolsApiClient
{
    private array $config;
    public array $ruleEvaluations = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }

    public function evaluateGenericNoMatchReply(array $mailbox, array $message, array $options = []): array
    {
        $if = (string) ($options['if_condition'] ?? '');
        $this->ruleEvaluations[] = $if;

        if (stripos($if, 'sales pitch') !== false) {
            return [
                'can_reply' => false,
                'certainty' => 'high',
                'reason' => 'Not this type.',
                'decision_reason_code' => 'no_matching_rule_generic_ai_rejected',
                'risk_flags' => ['not_matched'],
                'raw_response' => '{}',
                'reply' => '',
            ];
        }

        return [
            'can_reply' => true,
            'certainty' => 'high',
            'reason' => 'Rule matched.',
            'decision_reason_code' => '',
            'risk_flags' => [],
            'raw_response' => '{}',
            'reply' => 'Thanks, we can help with this request.',
            'model' => 'gpt-4o-mini',
        ];
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        return ['response' => 'stub'];
    }

    public function sendReplyViaTools(array $payload): array
    {
        return ['ok' => true];
    }
}

final class GenericNoMatchRowsImapMailboxClient extends ImapMailboxClient
{
    public array $sent = [];

    public function __construct(array $config = [])
    {
    }

    public function fetchUnseenMessages(int $limit = 20): array
    {
        return [[
            'uid' => 901,
            'message_id' => '<row-test@example.test>',
            'message_key' => 'row-test-key',
            'from' => 'sender@example.test',
            'to' => 'support@example.test',
            'subject' => 'Need support for invoice',
            'body_text' => 'Please help with invoice question',
            'is_seen' => false,
        ]];
    }

    public function markSeen(int $uid): bool
    {
        return true;
    }

    public function moveMessage(int $uid, string $folder): bool
    {
        return true;
    }

    public function deleteMessage(int $uid): bool
    {
        return true;
    }
}

final class GenericNoMatchRowsRunner extends MailAssistantRunner
{
    protected function createImapMailboxClient(array $config): ImapMailboxClient
    {
        return new GenericNoMatchRowsImapMailboxClient();
    }
}

$tmp = sys_get_temp_dir() . '/mail-assistant-generic-rows-' . uniqid('', true);
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/logs', 0777, true);
@mkdir($tmp . '/state', 0777, true);

$logger = new Logger($tmp . '/logs/test.log', $tmp . '/last-run.json');
$state = new MessageStateStore($tmp . '/state/message-state.json', 100);
$config = [
    'mailboxes' => [[
        'id' => 33,
        'name' => 'Rows mailbox',
        'imap' => [],
        'defaults' => [
            'from_name' => 'Support',
            'from_email' => 'support@example.test',
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_rules' => [
                [
                    'id' => 1,
                    'sort_order' => 0,
                    'is_active' => true,
                    'if' => 'If this is an unsolicited sales pitch we should decline.',
                    'instruction' => 'Decline politely.',
                ],
                [
                    'id' => 2,
                    'sort_order' => 10,
                    'is_active' => true,
                    'if' => 'If this is a normal support request, we may answer.',
                    'instruction' => 'Answer shortly and clearly.',
                ],
            ],
        ],
        'rules' => [],
    ]],
];

$tools = new GenericNoMatchRowsToolsApiClient($config);
$runner = new GenericNoMatchRowsRunner($tools, $logger, $state);
$result = $runner->run(['dry_run' => true]);

if (($result['messages_handled'] ?? 0) !== 1) {
    throw new RuntimeException('Expected one handled message.');
}
if (count($tools->ruleEvaluations) !== 2) {
    throw new RuntimeException('Expected two IF rows to be evaluated in order.');
}
if (stripos((string) $tools->ruleEvaluations[0], 'sales pitch') === false) {
    throw new RuntimeException('Expected first evaluated row to be sales pitch row.');
}
if (stripos((string) $tools->ruleEvaluations[1], 'support request') === false) {
    throw new RuntimeException('Expected second evaluated row to be support row.');
}

echo "generic-no-match-rows-regression: ok\n";


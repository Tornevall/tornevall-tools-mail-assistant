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
    private bool $throwOnSalesPitch;

    public function __construct(array $config, bool $throwOnSalesPitch = false)
    {
        parent::__construct('https://example.invalid/api', 'test-token');
        $this->config = $config;
        $this->throwOnSalesPitch = $throwOnSalesPitch;
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
            if ($this->throwOnSalesPitch) {
                throw new RuntimeException('Synthetic row-level AI/API failure for first unmatched row.');
            }

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

final class RejectingSupportGenericNoMatchToolsApiClient extends ToolsApiClient
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
        $if = (string) ($options['if_condition'] ?? '');
        $this->ruleEvaluations[] = $if;

        if (stripos($if, 'sales pitch') !== false) {
            return [
                'can_reply' => false,
                'certainty' => 'high',
                'reason' => 'Sales row rejected this message.',
                'decision_reason_code' => 'no_matching_rule_generic_ai_rejected',
                'risk_flags' => ['unsolicited_sales'],
                'raw_response' => '{}',
                'reply' => '',
            ];
        }

        return [
            'can_reply' => false,
            'certainty' => 'high',
            'reason' => 'Support row rejected the message as unsafe to answer.',
            'decision_reason_code' => 'no_matching_rule_generic_ai_rejected',
            'risk_flags' => ['support_row_reject'],
            'raw_response' => '{}',
            'reply' => '',
        ];
    }
}

final class GenericNoMatchRowsImapMailboxClient extends ImapMailboxClient
{
    public array $sent = [];
    public array $markSeenCalls = [];
    public array $markUnseenCalls = [];

    public function __construct(array $config = [])
    {
        parent::__construct($config);
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
        $this->markSeenCalls[] = $uid;

        return true;
    }

    public function markUnseen(int $uid): bool
    {
        $this->markUnseenCalls[] = $uid;

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
    private GenericNoMatchRowsImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, ?GenericNoMatchRowsImapMailboxClient $imap = null)
    {
        parent::__construct($tools, $logger, $messageState);
        $this->imap = $imap ?: new GenericNoMatchRowsImapMailboxClient();
    }

    protected function createImapMailboxClient(array $config): ImapMailboxClient
    {
        return $this->imap;
    }
}

function assertTrueCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runGenericNoMatchRowsScenario(bool $throwOnSalesPitch, bool $rejectSupportRow = false, bool $dryRun = true): array
{
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

    $tools = $rejectSupportRow
        ? new RejectingSupportGenericNoMatchToolsApiClient($config)
        : new GenericNoMatchRowsToolsApiClient($config, $throwOnSalesPitch);

    $imap = new GenericNoMatchRowsImapMailboxClient();
    $runner = new GenericNoMatchRowsRunner($tools, $logger, $state, $imap);
    $result = $runner->run(['dry_run' => $dryRun]);

    return [$tools, $result, $imap];
}

[$tools, $result] = runGenericNoMatchRowsScenario(false);

assertTrueCondition(($result['messages_handled'] ?? 0) === 1, 'Expected one handled message for clean row rejection fallthrough.');
assertTrueCondition(count($tools->ruleEvaluations) === 2, 'Expected two IF rows to be evaluated in order for clean row rejection fallthrough.');
assertTrueCondition(stripos((string) $tools->ruleEvaluations[0], 'sales pitch') !== false, 'Expected first evaluated row to be sales pitch row.');
assertTrueCondition(stripos((string) $tools->ruleEvaluations[1], 'support request') !== false, 'Expected second evaluated row to be support row.');
assertTrueCondition(count((array) ($result['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'] ?? [])) === 2, 'Expected generic no-match diagnostics to record both evaluated rows after a clean rejection.');

[$failingTools, $failingResult] = runGenericNoMatchRowsScenario(true);

assertTrueCondition(($failingResult['messages_handled'] ?? 0) === 1, 'Expected one handled message when the first unmatched row evaluation fails but a later row allows a reply.');
assertTrueCondition(count($failingTools->ruleEvaluations) === 2, 'Expected later unmatched rows to still be evaluated after a row-local evaluation failure.');
assertTrueCondition(count((array) ($failingResult['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'] ?? [])) === 2, 'Expected diagnostics to retain both evaluated rows after a row-local failure.');
assertTrueCondition(((array) ($failingResult['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'] ?? []))[0]['decision_reason_code'] === 'no_matching_rule_generic_ai_error', 'Expected the first evaluated row to be recorded as a row-local generic AI error.');
assertTrueCondition(((array) ($failingResult['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'] ?? []))[1]['can_reply'] === true, 'Expected the later unmatched row to remain eligible to reply after the first row failed.');

[$rejectingTools, $rejectingResult, $rejectingImap] = runGenericNoMatchRowsScenario(false, true, false);

assertTrueCondition(($rejectingResult['messages_skipped'] ?? 0) === 1, 'Expected one skipped message when all active unmatched rows reject.');
assertTrueCondition(($rejectingResult['messages_handled'] ?? 0) === 0, 'Expected no handled messages when all active unmatched rows reject.');
assertTrueCondition(count($rejectingTools->ruleEvaluations) === 2, 'Expected both unmatched rows to be evaluated before the runner gives up.');
assertTrueCondition(($rejectingResult['mailboxes'][0]['message_results'][0]['reason'] ?? '') === 'no_matching_rule_generic_ai_rejected', 'Expected the run summary to keep the strict reject reason when all unmatched rows reject.');
assertTrueCondition(count((array) ($rejectingResult['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'] ?? [])) === 2, 'Expected rejection diagnostics to record both evaluated unmatched rows.');
assertTrueCondition(((array) ($rejectingResult['mailboxes'][0]['message_results'][0]['generic_ai_decision']['risk_flags'] ?? [])) === ['support_row_reject'], 'Expected the last rejecting row risk flags to remain visible in diagnostics.');
assertTrueCondition($rejectingImap->markSeenCalls === [], 'Rejected unmatched rows must not mark the message seen.');
assertTrueCondition($rejectingImap->markUnseenCalls === [901], 'Rejected unmatched rows must explicitly preserve unread state when IMAP supports it.');

echo "generic-no-match-rows-regression: ok\n";


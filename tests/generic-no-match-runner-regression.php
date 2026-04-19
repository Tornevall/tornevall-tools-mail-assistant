<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class RejectingGenericNoMatchToolsApiClient extends ToolsApiClient
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

        $reason = stripos($ifCondition, 'follow-up') !== false
            ? 'Second unmatched row also rejected the message.'
            : 'Unsolicited sales mail should not be answered.';
        $riskFlags = stripos($ifCondition, 'follow-up') !== false
            ? ['follow_up_reject']
            : ['unsolicited_sales'];

        return [
            'can_reply' => false,
            'certainty' => 'high',
            'reason' => $reason,
            'decision_reason_code' => 'no_matching_rule_generic_ai_rejected',
            'reply' => '',
            'response' => '',
            'risk_flags' => $riskFlags,
            'raw_response' => '{"can_reply":false}',
            'parsed_decision' => [
                'valid_json' => true,
                'can_reply' => false,
                'certainty' => 'high',
                'reason' => $reason,
                'risk_flags' => $riskFlags,
                'reply' => '',
            ],
        ];
    }
}

final class TrackingGenericNoMatchImapMailboxClient extends ImapMailboxClient
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

    public function moveMessage(int $uid, string $folder): bool
    {
        throw new RuntimeException('moveMessage() should not be called when generic unmatched-mail AI rejects the mail.');
    }

    public function deleteMessage(int $uid): bool
    {
        throw new RuntimeException('deleteMessage() should not be called when generic unmatched-mail AI rejects the mail.');
    }
}

final class TestableGenericNoMatchRunner extends MailAssistantRunner
{
    private TrackingGenericNoMatchImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, TrackingGenericNoMatchImapMailboxClient $imap)
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

function makeTempPath(string $prefix, string $suffix): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);

    return $base . $suffix;
}

$config = [
    'mailboxes' => [[
        'id' => 12,
        'name' => 'Regression mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'mark_seen_on_skip' => true,
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_rules' => [
                [
                    'id' => 41,
                    'sort_order' => 0,
                    'is_active' => true,
                    'if' => 'If the unmatched mail is a routine support request that is safe to answer, we may reply.',
                    'instruction' => 'Answer politely and clearly when safe.',
                ],
                [
                    'id' => 42,
                    'sort_order' => 10,
                    'is_active' => true,
                    'if' => 'If a later follow-up unmatched row also applies, it should still be evaluated before we give up.',
                    'instruction' => 'Fallback answer instruction for later row.',
                ],
            ],
        ],
        'rules' => [],
    ]],
];

$message = [
    'uid' => 303,
    'is_seen' => false,
    'message_id' => '<reject@example.test>',
    'message_key' => '<reject@example.test>',
    'subject' => 'Please buy our design services',
    'subject_normalized' => 'Please buy our design services',
    'from' => 'seller@example.test',
    'to' => 'support@example.test',
    'date' => 'Fri, 18 Apr 2026 12:00:00 +0000',
    'body_text' => 'We would like to sell unsolicited design services.',
    'body_text_reply_aware' => 'We would like to sell unsolicited design services.',
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json'));
$messageState = new MessageStateStore(makeTempPath('mail-assistant-state', '.json'));
$imap = new TrackingGenericNoMatchImapMailboxClient([$message]);
$tools = new RejectingGenericNoMatchToolsApiClient($config);
$runner = new TestableGenericNoMatchRunner($tools, $logger, $messageState, $imap);
$summary = $runner->run(['include_history' => true]);

assertSameValue(1, $summary['messages_skipped'] ?? null, 'Rejected unmatched-mail triage should count as skipped.');
assertSameValue(0, count($imap->markSeenCalls), 'Rejected unmatched-mail triage must leave the message unread.');
assertSameValue([303], $imap->markUnseenCalls, 'Rejected unmatched-mail triage should explicitly keep the message unread when IMAP supports it.');
assertSameValue('no_matching_rule_generic_ai_rejected', $summary['mailboxes'][0]['message_state_records'][0]['reason'] ?? null, 'Rejected unmatched-mail triage should persist the strict reject reason.');
assertSameValue('no_matching_rule_generic_ai_rejected', $summary['mailboxes'][0]['message_results'][0]['reason'] ?? null, 'Rejected unmatched-mail triage should also appear in the current-run result summary.');
assertSameValue(['follow_up_reject'], $summary['mailboxes'][0]['message_results'][0]['generic_ai_decision']['risk_flags'] ?? null, 'Rejected unmatched-mail triage should preserve the last evaluated row risk flags for diagnostics.');
assertSameValue(2, count($tools->ruleEvaluations), 'Rejected unmatched-mail triage should still evaluate later active no-match rows before giving up.');
assertSameValue(2, count($summary['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'] ?? []), 'Rejected unmatched-mail diagnostics should record every evaluated no-match row.');
assertSameValue(41, $summary['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'][0]['id'] ?? null, 'Expected the first no-match row to be recorded in evaluation diagnostics.');
assertSameValue(42, $summary['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'][1]['id'] ?? null, 'Expected the later no-match row to be recorded in evaluation diagnostics.');

fwrite(STDOUT, "generic-no-match-runner-regression: ok\n");


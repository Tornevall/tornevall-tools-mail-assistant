<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class FinalFallbackToolsApiClient extends ToolsApiClient
{
    private array $config;
    public array $ruleEvaluations = [];
    public array $relayPayloads = [];

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

        if (stripos($ifCondition, 'sales pitch') !== false) {
            return [
                'can_reply' => false,
                'certainty' => 'high',
                'reason' => 'This advanced unmatched row did not match the message.',
                'decision_reason_code' => 'no_matching_rule_generic_ai_rejected',
                'reply' => '',
                'response' => '',
                'risk_flags' => ['not_matched'],
                'raw_response' => '{"can_reply":false}',
            ];
        }

        return [
            'can_reply' => true,
            'certainty' => 'high',
            'reason' => 'The strict mailbox-level final fallback matched safely.',
            'decision_reason_code' => 'no_matching_rule_generic_ai_replied',
            'reply' => 'Thanks for your message. This final fallback can safely ask for one more account detail before support continues.',
            'response' => 'Thanks for your message. This final fallback can safely ask for one more account detail before support continues.',
            'risk_flags' => [],
            'raw_response' => '{"can_reply":true}',
            'model' => 'gpt-4o-mini',
        ];
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

final class FinalFallbackImapMailboxClient extends ImapMailboxClient
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
        throw new RuntimeException('moveMessage() should not be used for the final unmatched fallback regression.');
    }

    public function deleteMessage(int $uid): bool
    {
        throw new RuntimeException('deleteMessage() should not be used for the final unmatched fallback regression.');
    }
}

final class FinalFallbackRunner extends MailAssistantRunner
{
    private FinalFallbackImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, FinalFallbackImapMailboxClient $imap)
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

putenv('MAIL_ASSISTANT_MAIL_TRANSPORT=tools_api');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=0');
putenv('MAIL_ASSISTANT_TOOLS_MAIL_TOKEN=test-mail-token');

$config = [
    'mailboxes' => [[
        'id' => 77,
        'name' => 'Final fallback mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'from_name' => 'Support Team',
            'from_email' => 'support@example.test',
            'mark_seen_on_skip' => true,
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_ai_model' => 'gpt-4o-mini',
            'generic_no_match_ai_reasoning_effort' => 'medium',
            'generic_no_match_if' => 'If the unmatched mail is a normal support question and clearly not spam, fraud, phishing, or vague sales outreach, the final fallback may answer.',
            'generic_no_match_instruction' => 'Reply briefly, ask for the missing account detail, and stay on the sender\'s language.',
            'generic_no_match_footer' => 'Kind regards',
            'generic_no_match_rules' => [[
                'id' => 51,
                'sort_order' => 0,
                'is_active' => true,
                'if' => 'If the unmatched mail is an unsolicited sales pitch, we should decline.',
                'instruction' => 'Decline politely and do not invite follow-up.',
            ]],
        ],
        'rules' => [],
    ]],
];

$message = [
    'uid' => 404,
    'is_seen' => false,
    'message_id' => '<final-fallback@example.test>',
    'message_key' => '<final-fallback@example.test>',
    'subject' => 'Need help with my account',
    'subject_normalized' => 'Need help with my account',
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'date' => 'Mon, 21 Apr 2026 12:00:00 +0000',
    'body_text' => 'Hi, no normal rule matched this yet. I need help with my account.',
    'body_text_reply_aware' => 'Hi, no normal rule matched this yet. I need help with my account.',
    'spam_assassin' => ['present' => false],
];

$logger = new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json'));
$messageState = new MessageStateStore(makeTempPath('mail-assistant-state', '.json'));
$imap = new FinalFallbackImapMailboxClient([$message]);
$tools = new FinalFallbackToolsApiClient($config);
$runner = new FinalFallbackRunner($tools, $logger, $messageState, $imap);
$summary = $runner->run(['include_history' => true]);

assertSameValue(1, $summary['messages_handled'] ?? null, 'The strict mailbox-level final fallback should handle the unmatched message.');
assertSameValue([
    'If the unmatched mail is an unsolicited sales pitch, we should decline.',
    'If the unmatched mail is a normal support question and clearly not spam, fraud, phishing, or vague sales outreach, the final fallback may answer.',
], $tools->ruleEvaluations, 'The runner should try advanced unmatched rows before the mailbox-level final fallback.');
assertSameValue([404], $imap->markSeenCalls, 'A sent mailbox-level final fallback reply must mark the message as seen afterwards.');
assertSameValue([], $imap->markUnseenCalls, 'A handled mailbox-level final fallback reply must not push the message back to unread.');
assertSameValue('mark_seen', $summary['mailboxes'][0]['message_results'][0]['post_handle_action'] ?? null, 'Handled final fallback replies should report mark_seen as the post-handle action.');
assertSameValue(true, $summary['mailboxes'][0]['message_results'][0]['generic_ai_decision']['evaluated_no_match_rules'][1]['is_mailbox_final_fallback'] ?? null, 'The second evaluated unmatched rule should be the mailbox-level final fallback.');
assertSameValue(1, count($tools->relayPayloads), 'The final unmatched fallback should send exactly one reply through the configured transport.');

fwrite(STDOUT, "generic-no-match-final-fallback-regression: ok\n");

putenv('MAIL_ASSISTANT_MAIL_TRANSPORT');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API');
putenv('MAIL_ASSISTANT_TOOLS_MAIL_TOKEN');


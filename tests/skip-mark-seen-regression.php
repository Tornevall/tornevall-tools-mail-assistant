<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class FakeToolsApiClient extends ToolsApiClient
{
    private array $config;
    private bool $failOnGenericNoMatchEvaluation;

    public function __construct(array $config, bool $failOnGenericNoMatchEvaluation = false)
    {
        parent::__construct('https://example.invalid/api', 'test-token');
        $this->config = $config;
        $this->failOnGenericNoMatchEvaluation = $failOnGenericNoMatchEvaluation;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }

    public function evaluateGenericNoMatchReply(array $mailbox, array $message, array $options = []): array
    {
        if ($this->failOnGenericNoMatchEvaluation) {
            throw new RuntimeException('Generic no-match AI must not be evaluated when the mailbox checkbox is disabled.');
        }

        return parent::evaluateGenericNoMatchReply($mailbox, $message, $options);
    }
}

final class FakeImapMailboxClient extends ImapMailboxClient
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
        throw new RuntimeException('moveMessage() should not be called in this regression test.');
    }

    public function deleteMessage(int $uid): bool
    {
        throw new RuntimeException('deleteMessage() should not be called in this regression test.');
    }
}

final class TestableMailAssistantRunner extends MailAssistantRunner
{
    private FakeImapMailboxClient $imap;

    public function __construct(ToolsApiClient $tools, Logger $logger, MessageStateStore $messageState, FakeImapMailboxClient $imap)
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

function runCase(array $mailboxDefaults, array $message, bool $failOnGenericNoMatchEvaluation = false): array
{
    $config = [
        'mailboxes' => [[
            'id' => 5,
            'name' => 'Regression mailbox',
            'imap' => [],
            'defaults' => array_merge([
                'run_limit' => 20,
                'mark_seen_on_skip' => true,
            ], $mailboxDefaults),
            'rules' => [],
        ]],
    ];

    $logger = new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json'));
    $messageState = new MessageStateStore(makeTempPath('mail-assistant-state', '.json'));
    $imap = new FakeImapMailboxClient([$message]);
    $runner = new TestableMailAssistantRunner(new FakeToolsApiClient($config, $failOnGenericNoMatchEvaluation), $logger, $messageState, $imap);

    $summary = $runner->run(['include_history' => true]);

    return [$summary, $imap];
}

putenv('MAIL_ASSISTANT_GENERIC_NO_MATCH_AI=1');

[$noMatchSummary, $noMatchImap] = runCase(
    [
        'generic_no_match_ai_enabled' => false,
        'generic_no_match_if' => 'If the unmatched mail is a normal support request and clearly not spam, fraud, phishing, or sales, a fallback reply may be allowed.',
        'generic_no_match_instruction' => 'Reply briefly and safely when allowed.',
        'generic_no_match_rules' => [
            [
                'id' => 9001,
                'sort_order' => 0,
                'is_active' => true,
                'if' => 'If this is a normal support request we may answer.',
                'instruction' => 'Answer politely.',
            ],
        ],
    ],
    [
        'uid' => 101,
        'is_seen' => false,
        'message_id' => '<nomatch@example.test>',
        'message_key' => '<nomatch@example.test>',
        'subject' => 'Unknown support question',
        'subject_normalized' => 'Unknown support question',
        'from' => 'sender@example.test',
        'to' => 'support@example.test',
        'date' => 'Fri, 18 Apr 2026 10:00:00 +0000',
        'body_text' => 'No rule matches this message.',
        'body_text_reply_aware' => 'No rule matches this message.',
        'spam_assassin' => ['present' => false],
    ],
    true
);

assertSameValue(1, $noMatchSummary['messages_skipped'] ?? null, 'No-match case should be counted as skipped.');
assertSameValue(0, count($noMatchImap->markSeenCalls), 'No-match/configuration-driven skip must stay unread.');
assertSameValue([101], $noMatchImap->markUnseenCalls, 'No-match/configuration-driven skip should explicitly keep the message unread when supported.');
assertSameValue(
    'no_matching_rule_generic_ai_disabled',
    $noMatchSummary['mailboxes'][0]['message_state_records'][0]['reason'] ?? null,
    'No-match case should record the generic-AI-disabled reason.'
);

[$spamSummary, $spamImap] = runCase(
    [],
    [
        'uid' => 202,
        'is_seen' => false,
        'message_id' => '<spam@example.test>',
        'message_key' => '<spam@example.test>',
        'subject' => 'Obvious spam',
        'subject_normalized' => 'Obvious spam',
        'from' => 'spam@example.test',
        'to' => 'support@example.test',
        'date' => 'Fri, 18 Apr 2026 11:00:00 +0000',
        'body_text' => 'Spam body',
        'body_text_reply_aware' => 'Spam body',
        'spam_assassin' => [
            'present' => true,
            'flagged' => true,
            'score' => 12.5,
            'tests' => ['BAYES_99'],
        ],
    ]
);

assertSameValue(1, $spamSummary['messages_spamassassin_skipped'] ?? null, 'SpamAssassin case should be counted separately.');
assertSameValue([202], $spamImap->markSeenCalls, 'Explicit heuristic skip should still honor mark_seen_on_skip.');
assertSameValue([], $spamImap->markUnseenCalls, 'Explicit heuristic skip should not force unread when operator wants it marked seen.');

fwrite(STDOUT, "skip-mark-seen-regression: ok\n");

putenv('MAIL_ASSISTANT_GENERIC_NO_MATCH_AI');


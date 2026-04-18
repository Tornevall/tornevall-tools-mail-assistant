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

    public function __construct(array $config)
    {
        parent::__construct('https://example.invalid/api', 'test-token');
        $this->config = $config;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }
}

final class FakeImapMailboxClient extends ImapMailboxClient
{
    private array $messages;
    public array $markSeenCalls = [];

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

function runCase(array $mailboxDefaults, array $message): array
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
    $runner = new TestableMailAssistantRunner(new FakeToolsApiClient($config), $logger, $messageState, $imap);

    $summary = $runner->run();

    return [$summary, $imap];
}

[$noMatchSummary, $noMatchImap] = runCase(
    ['generic_no_match_ai_enabled' => false],
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
    ]
);

assertSameValue(1, $noMatchSummary['messages_skipped'] ?? null, 'No-match case should be counted as skipped.');
assertSameValue(0, count($noMatchImap->markSeenCalls), 'No-match/configuration-driven skip must stay unread.');
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

fwrite(STDOUT, "skip-mark-seen-regression: ok\n");


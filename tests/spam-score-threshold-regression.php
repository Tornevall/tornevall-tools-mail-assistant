<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\ImapMailboxClient;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class SpamScoreThresholdToolsApiClient extends ToolsApiClient
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

final class SpamScoreThresholdImapMailboxClient extends ImapMailboxClient
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
}

final class SpamScoreThresholdRunner extends MailAssistantRunner
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

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$config = [
    'mailboxes' => [[
        'id' => 9,
        'name' => 'Spam-threshold mailbox',
        'imap' => [],
        'defaults' => [
            'run_limit' => 20,
            'mark_seen_on_skip' => true,
            'spam_score_reply_threshold' => 6.5,
        ],
        'rules' => [[
            'id' => 91,
            'name' => 'Would normally reply',
            'sort_order' => 0,
            'match' => [
                'from_contains' => 'customer@example.test',
                'to_contains' => '',
                'subject_contains' => 'Invoice',
                'body_contains' => '',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => false,
                'template_text' => 'Thanks, we got your invoice question.',
                'subject_prefix' => 'Re:',
                'from_name' => 'Support',
                'from_email' => 'support@example.test',
            ],
            'post_handle' => [
                'move_to_folder' => '',
                'delete_after_handle' => false,
            ],
        ]],
    ]],
];

$message = [
    'uid' => 303,
    'is_seen' => false,
    'message_id' => '<spam-threshold@example.test>',
    'message_key' => '<spam-threshold@example.test>',
    'subject' => 'Invoice follow-up',
    'subject_normalized' => 'Invoice follow-up',
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'date' => 'Sat, 19 Apr 2026 15:00:00 +0000',
    'body_text' => 'Could you clarify invoice line 4?',
    'body_text_reply_aware' => 'Could you clarify invoice line 4?',
    'spam_assassin' => [
        'present' => true,
        'flagged' => false,
        'score' => 7.2,
        'tests' => ['BAYES_80'],
    ],
];

$logger = new Logger(sys_get_temp_dir() . '/mail-assistant-spam-threshold.log', sys_get_temp_dir() . '/mail-assistant-spam-threshold-last-run.json');
$state = new MessageStateStore(sys_get_temp_dir() . '/mail-assistant-spam-threshold-state.json');
$imap = new SpamScoreThresholdImapMailboxClient([$message]);
$runner = new SpamScoreThresholdRunner(new SpamScoreThresholdToolsApiClient($config), $logger, $state, $imap);

$result = $runner->run(['include_history' => true]);

assertSameValue(1, $result['messages_skipped'] ?? null, 'Message should be skipped when score exceeds configured reply threshold.');
assertSameValue(1, $result['messages_reply_spam_score_suppressed'] ?? null, 'Reply-suppressed counter should be incremented.');
assertSameValue([], $imap->markSeenCalls, 'Threshold-based reply suppression must not mark message as seen.');
assertSameValue([303], $imap->markUnseenCalls, 'Threshold-based suppression should explicitly keep message unread.');
assertSameValue(
    'spam_score_reply_threshold_exceeded',
    $result['mailboxes'][0]['message_state_records'][0]['reason'] ?? null,
    'State reason should show reply suppression threshold hit.'
);

fwrite(STDOUT, "spam-score-threshold-regression: ok\n");


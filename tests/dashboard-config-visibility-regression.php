<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use MailSupportAssistant\Auth\SimpleSessionAuth;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;
use MailSupportAssistant\Web\WebApp;

final class DashboardVisibilityFakeToolsApiClient extends ToolsApiClient
{
    private array $config;

    public function __construct(array $config)
    {
        parent::__construct('https://example.invalid/api', 'standalone-test-token');
        $this->config = $config;
    }

    public function hasToken(): bool
    {
        return true;
    }

    public function fetchConfig(): array
    {
        return $this->config;
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'user' => [
        'id' => 7,
        'name' => 'Dashboard Operator',
    ],
    'token' => [
        'provider' => 'provider_mail_support_assistant',
        'user_id' => 7,
        'is_ai' => true,
    ],
    'mailboxes' => [[
        'id' => 12,
        'name' => 'Support Inbox',
        'imap' => [
            'host' => 'imap.example.test',
            'port' => 993,
            'encryption' => 'ssl',
            'folder' => 'INBOX',
        ],
        'defaults' => [
            'from_name' => 'Support Team',
            'from_email' => 'support@example.test',
            'bcc' => 'audit@example.test',
            'footer' => 'Kind regards',
            'run_limit' => 15,
            'mark_seen_on_skip' => false,
            'spam_score_reply_threshold' => 6.5,
            'generic_no_match_ai_enabled' => true,
            'generic_no_match_ai_model' => 'gpt-4o-mini',
            'generic_no_match_ai_reasoning_effort' => 'medium',
            'generic_no_match_if' => 'If the message is an unsolicited sales pitch, we can decline.',
            'generic_no_match_instruction' => 'Decline politely and briefly.',
            'generic_no_match_footer' => 'Kind regards',
            'subject_trim_prefixes' => ['[SPAMASSASSIN]'],
            'generic_no_match_rules' => [[
                'id' => 31,
                'sort_order' => 10,
                'is_active' => true,
                'if' => 'If the message is an unsolicited sales pitch, we can decline.',
                'instruction' => 'Decline politely and briefly.',
                'footer' => 'Kind regards',
                'ai_model' => 'gpt-4o-mini',
                'ai_reasoning_effort' => 'medium',
            ]],
        ],
        'notes' => 'Main support mailbox.',
        'rules' => [[
            'id' => 22,
            'name' => 'Order status',
            'sort_order' => 5,
            'match' => [
                'from_contains' => 'customer@example.test',
                'to_contains' => 'support@example.test',
                'subject_contains' => 'order status',
                'body_contains' => 'order',
            ],
            'reply' => [
                'enabled' => true,
                'ai_enabled' => true,
                'subject_prefix' => 'Re:',
                'from_name' => 'Support Team',
                'from_email' => 'support@example.test',
                'bcc' => 'audit@example.test',
                'template_text' => 'Thanks for your message.',
                'footer_mode' => 'static',
                'footer_text' => 'Kind regards',
                'responder_name' => 'Alex',
                'persona_profile' => 'Helpful support agent',
                'mood' => 'calm',
                'custom_instruction' => 'Ask for the order number if missing.',
                'ai_model' => 'gpt-4o-mini',
                'ai_reasoning_effort' => 'medium',
            ],
            'post_handle' => [
                'move_to_folder' => 'Handled',
                'delete_after_handle' => false,
            ],
            'subject_trim_prefixes' => ['Re:'],
            'fallback_rule' => [
                'enabled' => true,
                'if_condition' => 'If the mail lacks an order number.',
                'instruction' => 'Ask for the missing order number.',
                'ai_model' => 'gpt-4o-mini',
                'ai_reasoning_effort' => 'low',
            ],
        ]],
    ]],
];

$logFile = sys_get_temp_dir() . '/mail-assistant-dashboard-visibility.log';
$lastRunFile = sys_get_temp_dir() . '/mail-assistant-dashboard-visibility-last-run.json';
@unlink($logFile);
@unlink($lastRunFile);

$tools = new DashboardVisibilityFakeToolsApiClient($config);
$logger = new Logger($logFile, $lastRunFile);
$runner = new MailAssistantRunner($tools, $logger);
$app = new WebApp(new SimpleSessionAuth(), $logger, $tools, $runner);

$method = new ReflectionMethod($app, 'buildDashboardPayload');
$method->setAccessible(true);
/** @var array $payload */
$payload = $method->invoke($app);

$configSummary = $payload['ui']['config'] ?? [];
$activitySummary = $payload['ui']['activity'] ?? [];

assertSameValue(1, count((array) ($configSummary['mailboxes'] ?? [])), 'Dashboard config summary should expose the fetched mailbox list.');
assertSameValue('gpt-4o-mini', $configSummary['mailboxes'][0]['defaults']['generic_no_match_ai_model'] ?? null, 'Dashboard config summary should expose mailbox-level unmatched AI model settings.');
assertSameValue('Ask for the missing order number.', $configSummary['mailboxes'][0]['rules'][0]['fallback_rule']['instruction'] ?? null, 'Dashboard config summary should expose readable per-rule fallback instruction details.');
assertSameValue('Kind regards', $configSummary['mailboxes'][0]['no_match_rules'][0]['footer'] ?? null, 'Dashboard config summary should expose unmatched row footer/model/reasoning fields.');
assertTrueValue(!empty($activitySummary), 'Dashboard activity summary should still list configured mailboxes even before any run has been saved.');
assertSameValue('config_only', $activitySummary[0]['source'] ?? null, 'Configured mailboxes without a last run should appear as config-only activity cards.');
assertSameValue('imap.example.test', $activitySummary[0]['imap']['host'] ?? null, 'Config-only activity cards should still expose mailbox connection context.');

fwrite(STDOUT, "dashboard-config-visibility-regression: ok\n");


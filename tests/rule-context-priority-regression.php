<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$runner = new MailAssistantRunner(
    new class extends \MailSupportAssistant\Tools\ToolsApiClient {
        public function __construct()
        {
            parent::__construct('https://example.invalid/api', 'test-token');
        }
    },
    new \MailSupportAssistant\Support\Logger(sys_get_temp_dir() . '/mail-assistant-context-priority.log', sys_get_temp_dir() . '/mail-assistant-context-priority-last-run.json'),
    new \MailSupportAssistant\Support\MessageStateStore(sys_get_temp_dir() . '/mail-assistant-context-priority-state.json')
);

$method = new ReflectionMethod(MailAssistantRunner::class, 'findMatchingRules');
$method->setAccessible(true);

$matches = $method->invoke($runner, [
    'from' => 'Example Sender <thomas.tornevall@gmail.com>',
    'to' => 'support@tornevall.net',
    'subject' => 'copyright-test',
    'subject_normalized' => 'copyright-test',
    'body_text' => 'Please review the copyright-test thread.',
    'body_text_reply_aware' => 'Please review the copyright-test thread.',
], [
    [
        'id' => 2,
        'name' => 'Gmail-Thomas',
        'sort_order' => 0,
        'match' => [
            'from_contains' => 'thomas.tornevall@gmail.com',
            'to_contains' => '',
            'subject_contains' => '',
            'body_contains' => '',
        ],
    ],
    [
        'id' => 4,
        'name' => 'Copyright Notice Test',
        'sort_order' => 0,
        'match' => [
            'from_contains' => '',
            'to_contains' => '',
            'subject_contains' => 'copyright-test',
            'body_contains' => '',
        ],
    ],
]);

$selected = $matches[0] ?? null;
if (!is_array($selected)) {
    throw new RuntimeException('Expected matching rules to be returned.');
}

assertSameValue('Copyright Notice Test', $selected['rule']['name'] ?? null, 'When sort_order is tied, a direct subject rule should outrank a generic sender rule.');
assertSameValue(400, $selected['match_priority_score'] ?? null, 'Subject matches should carry the higher contextual priority score.');

fwrite(STDOUT, "rule-context-priority-regression: ok\n");


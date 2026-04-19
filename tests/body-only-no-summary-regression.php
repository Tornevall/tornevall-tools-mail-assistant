<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;

function makeAccessible(ReflectionMethod $method): void
{
    $method->setAccessible(true);
}

function assertStringNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message . ' Unexpected fragment: ' . $needle);
    }
}

$runner = new MailAssistantRunner(
    new class extends ToolsApiClient {
        public function __construct()
        {
            parent::__construct('https://example.invalid/api', 'test-token');
        }
    },
    new Logger(sys_get_temp_dir() . '/mail-assistant-body-only.log', sys_get_temp_dir() . '/mail-assistant-body-only-last-run.json')
);

$method = new ReflectionMethod($runner, 'buildReplyContent');
makeAccessible($method);

$result = $method->invoke(
    $runner,
    'Please resend the notice directly to abuse@no-ack.net.',
    [
        'body_text' => 'Notice body',
        'body_text_reply_aware' => 'Notice body',
    ],
    [
        'reply' => [
            'custom_instruction' => 'Write only the email body in English.',
        ],
    ]
);

if (!is_array($result)) {
    throw new RuntimeException('Expected reply content payload.');
}

assertStringNotContains('Summary of your request', (string) ($result['text'] ?? ''), 'Body-only instruction should suppress the appended request summary in the text body.');
assertStringNotContains('Summary of your request', (string) ($result['html'] ?? ''), 'Body-only instruction should suppress the appended request summary in the HTML body.');

fwrite(STDOUT, "body-only-no-summary-regression: ok\n");


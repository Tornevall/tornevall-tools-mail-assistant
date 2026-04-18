<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;

final class FailingToolsApiClient extends ToolsApiClient
{
    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        throw new RuntimeException('429; Too Many Attempts.');
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function makeAccessible(ReflectionMethod $method): void
{
    $method->setAccessible(true);
}

$runner = new MailAssistantRunner(new FailingToolsApiClient(), new Logger(sys_get_temp_dir() . '/mail-assistant-template-guard.log', sys_get_temp_dir() . '/mail-assistant-template-guard-last-run.json'));
$method = new ReflectionMethod($runner, 'buildReplyText');
makeAccessible($method);

$mailbox = ['defaults' => ['footer' => 'Kind regards']];
$message = [
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'subject' => 'Need help',
    'body_text' => 'Original message body.',
];

try {
    $method->invoke($runner, $mailbox, [
        'reply' => [
            'ai_enabled' => true,
            'template_text' => '',
        ],
    ], $message);

    throw new RuntimeException('Expected AI-enabled rule without explicit template to throw.');
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'No explicit fallback template is configured') === false) {
        throw new RuntimeException('Unexpected exception message: ' . $e->getMessage());
    }
}

$resultWithTemplate = $method->invoke($runner, $mailbox, [
    'reply' => [
        'ai_enabled' => true,
        'template_text' => 'Manual fallback for {{subject}}',
    ],
], $message);

assertSameValue("Manual fallback for Need help\n\nKind regards", $resultWithTemplate, 'Explicit template should still be usable as the operator-defined fallback for AI rules.');

fwrite(STDOUT, "ai-template-guard-regression: ok\n");


<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;

final class HtmlBodyCaptureToolsApiClient extends ToolsApiClient
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function request(string $method, string $path, ?array $payload = null, ?string $tokenOverride = null): array
    {
        $this->requests[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'payload' => $payload,
        ];

        if ($path !== '/ai/socialgpt/respond') {
            throw new RuntimeException('Unexpected request path: ' . $path);
        }

        return [
            'ok' => true,
            'model' => 'gpt-4o-mini',
            'response' => json_encode([
                'can_reply' => false,
                'certainty' => 'low',
                'reason' => 'Out of scope for this regression.',
                'risk_flags' => ['manual_review'],
                'reply' => '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}

function assertContainsFragment(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Missing fragment: ' . $needle);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function makeMethodAccessible(ReflectionMethod $method): void
{
    $method->setAccessible(true);
}

$client = new HtmlBodyCaptureToolsApiClient();
$message = [
    'from' => 'customer@example.test',
    'to' => 'support@example.test',
    'subject' => 'Hjälp',
    'subject_normalized' => 'Hjälp',
    'body_text' => '',
    'body_text_reply_aware' => '',
    'body_text_raw' => '',
    'body_html' => '<html lang="sv"><body><p>Hej!</p><p>Jag behöver hjälp med min beställning.</p><p>Ordernummer: 12345</p></body></html>',
    'spam_assassin' => ['present' => false],
];

$client->evaluateGenericNoMatchReply(
    ['name' => 'Regression mailbox'],
    $message,
    [
        'if_condition' => 'If the mail is a routine support question we may reply.',
        'reply_instruction' => 'Reply politely.',
        'ai_model' => 'gpt-4o-mini',
    ]
);

$context = (string) (($client->requests[0]['payload']['context'] ?? '') ?: '');
assertContainsFragment('Jag behöver hjälp med min beställning.', $context, 'HTML-only bodies must be decoded into AI context.');
assertContainsFragment('Ordernummer: 12345', $context, 'Decoded HTML body must preserve useful support details.');

$runner = new MailAssistantRunner(
    $client,
    new Logger(sys_get_temp_dir() . '/mail-assistant-html-body.log', sys_get_temp_dir() . '/mail-assistant-html-body-last-run.json')
);

$method = new ReflectionMethod($runner, 'findMatchingRules');
makeMethodAccessible($method);
$matches = $method->invoke($runner, $message, [[
    'id' => 99,
    'name' => 'HTML body support rule',
    'sort_order' => 1,
    'match' => [
        'from_contains' => '',
        'to_contains' => '',
        'subject_contains' => '',
        'body_contains' => 'beställning',
    ],
]]);

assertSameValue(1, is_array($matches) ? count($matches) : 0, 'HTML-only body content must also participate in body_contains rule matching.');

fwrite(STDOUT, "html-body-decoding-regression: ok\n");


<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Tools\ToolsApiClient;

final class RateLimitedToolsApiClient extends ToolsApiClient
{
    public int $attempts = 0;

    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function request(string $method, string $path, ?array $payload = null, ?string $tokenOverride = null): array
    {
        $this->attempts++;

        if ($this->attempts < 3) {
            throw new RuntimeException('429; Too Many Attempts.');
        }

        return [
            'ok' => true,
            'model' => (string) ($payload['model'] ?? ''),
            'response' => 'Recovered after retry',
        ];
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

putenv('MAIL_ASSISTANT_AI_RETRY_ATTEMPTS=3');
putenv('MAIL_ASSISTANT_AI_RETRY_DELAY_MS=1');

$client = new RateLimitedToolsApiClient();
$result = $client->generateAiReply(
    ['name' => 'Retry mailbox'],
    [
        'reply' => [
            'ai_model' => 'gpt-5.4',
        ],
    ],
    [
        'from' => 'sender@example.test',
        'to' => 'support@example.test',
        'subject' => 'Need help',
        'subject_normalized' => 'Need help',
        'body_text' => 'Original message body.',
        'body_text_reply_aware' => 'Original message body.',
        'spam_assassin' => ['present' => false],
    ]
);

assertSameValue(3, $client->attempts, 'AI request should retry rate-limited failures before succeeding.');
assertSameValue('Recovered after retry', $result['response'] ?? null, 'AI retry should return the eventual successful response.');

fwrite(STDOUT, "ai-rate-limit-retry-regression: ok\n");


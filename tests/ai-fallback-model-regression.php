<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Tools\ToolsApiClient;

final class FallbackCapturingToolsApiClient extends ToolsApiClient
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

        if (count($this->requests) === 1) {
            throw new RuntimeException('Primary model unavailable.');
        }

        return [
            'ok' => true,
            'model' => (string) ($payload['model'] ?? ''),
            'response' => 'Synthetic fallback response',
        ];
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function assertTrueValue($actual, string $message): void
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true) . '.');
    }
}

function assertMissingKey(string $key, array $payload, string $message): void
{
    if (array_key_exists($key, $payload)) {
        throw new RuntimeException($message . ' Unexpected key: ' . $key . '.');
    }
}

putenv('MAIL_ASSISTANT_AI_FALLBACK_MODEL=o4');
putenv('MAIL_ASSISTANT_AI_REASONING_EFFORT=medium');

$client = new FallbackCapturingToolsApiClient();
$result = $client->generateAiReply(
    ['name' => 'Primary mailbox'],
    [
        'reply' => [
            'ai_model' => 'gpt-5.4',
            'ai_reasoning_effort' => 'medium',
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

$primaryPayload = $client->requests[0]['payload'] ?? null;
$fallbackPayload = $client->requests[1]['payload'] ?? null;
if (!is_array($primaryPayload) || !is_array($fallbackPayload)) {
    throw new RuntimeException('Expected both the primary and fallback AI payloads to be captured.');
}

assertSameValue('gpt-5.4', $primaryPayload['model'] ?? null, 'Primary AI request should use the configured primary model.');
assertSameValue('medium', $primaryPayload['reasoning_effort'] ?? null, 'Primary AI request should keep reasoning effort for the primary reasoning-capable model.');
assertSameValue('o4', $fallbackPayload['model'] ?? null, 'Fallback AI request should default to o4.');
assertMissingKey('reasoning_effort', $fallbackPayload, 'Fallback AI request should omit reasoning effort for o4.');
assertSameValue('auto', $fallbackPayload['response_language'] ?? null, 'Fallback AI request should still keep same-language default behavior.');
assertTrueValue($result['used_fallback_model'] ?? false, 'AI result should report that the fallback model was used.');
assertSameValue('gpt-5.4', $result['fallback_from_model'] ?? null, 'AI result should report which primary model the fallback replaced.');
assertSameValue('o4', $result['model'] ?? null, 'AI result should expose the fallback model that succeeded.');

fwrite(STDOUT, "ai-fallback-model-regression: ok\n");


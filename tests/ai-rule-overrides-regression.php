<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Tools\ToolsApiClient;

final class CapturingToolsApiClient extends ToolsApiClient
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

        return [
            'ok' => true,
            'model' => (string) ($payload['model'] ?? ''),
            'response' => 'Synthetic AI response',
        ];
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function assertHasKey(string $key, array $payload, string $message): void
{
    if (!array_key_exists($key, $payload)) {
        throw new RuntimeException($message . ' Missing key: ' . $key . '.');
    }
}

$client = new CapturingToolsApiClient();

$client->generateAiReply(
    ['name' => 'Primary mailbox'],
    [
        'reply' => [
            'ai_model' => 'gpt-5.4',
            'ai_reasoning_effort' => 'high',
            'responder_name' => 'Thomas',
            'persona_profile' => 'Calm and factual support responder.',
            'custom_instruction' => 'Confirm receipt, summarize the issue, and ask for any missing ticket number.',
            'mood' => 'calm',
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

$rulePayload = $client->requests[0]['payload'] ?? null;
if (!is_array($rulePayload)) {
    throw new RuntimeException('Expected first AI request payload to be captured.');
}

assertSameValue('POST', $client->requests[0]['method'], 'Rule AI request should use POST.');
assertSameValue('/ai/socialgpt/respond', $client->requests[0]['path'], 'Rule AI request should target the Tools SocialGPT endpoint.');
assertSameValue('gpt-5.4', $rulePayload['model'] ?? null, 'Rule AI request should prefer the rule-selected model.');
assertSameValue('high', $rulePayload['reasoning_effort'] ?? null, 'Rule AI request should forward per-rule reasoning effort.');
assertSameValue('auto', $rulePayload['response_language'] ?? null, 'Rule AI request should default to matching the incoming mail language.');
assertSameValue('Thomas', $rulePayload['responder_name_override'] ?? null, 'Rule AI request should forward responder override.');
assertSameValue('Calm and factual support responder.', $rulePayload['persona_profile_override'] ?? null, 'Rule AI request should forward persona override.');
assertSameValue('Confirm receipt, summarize the issue, and ask for any missing ticket number.', $rulePayload['custom_instruction_override'] ?? null, 'Rule AI request should forward custom instruction override.');
assertSameValue('calm', $rulePayload['mood'] ?? null, 'Rule AI request should forward rule mood.');
assertHasKey('client_name', $rulePayload, 'Rule AI request should still identify the standalone client.');

$client->generateGenericAiReply(
    ['name' => 'Fallback mailbox'],
    [
        'from' => 'sender@example.test',
        'to' => 'support@example.test',
        'subject' => 'Unmatched question',
        'subject_normalized' => 'Unmatched question',
        'body_text' => 'Question without matching rule.',
        'body_text_reply_aware' => 'Question without matching rule.',
        'spam_assassin' => ['present' => false],
    ],
    [
        'custom_instruction' => 'Answer politely and ask for missing account details when necessary.',
        'ai_model' => 'gpt-4o-mini',
        'ai_reasoning_effort' => 'medium',
    ]
);

$genericPayload = $client->requests[1]['payload'] ?? null;
if (!is_array($genericPayload)) {
    throw new RuntimeException('Expected second AI request payload to be captured.');
}

assertSameValue('gpt-4o-mini', $genericPayload['model'] ?? null, 'Generic AI request should forward mailbox-selected model.');
assertSameValue('medium', $genericPayload['reasoning_effort'] ?? null, 'Generic AI request should forward mailbox-selected reasoning effort.');
assertSameValue('auto', $genericPayload['response_language'] ?? null, 'Generic AI request should also default to matching the incoming mail language.');
assertSameValue('Answer politely and ask for missing account details when necessary.', $genericPayload['custom_instruction_override'] ?? null, 'Generic AI request should forward mailbox custom instruction as an override.');

fwrite(STDOUT, "ai-rule-overrides-regression: ok\n");


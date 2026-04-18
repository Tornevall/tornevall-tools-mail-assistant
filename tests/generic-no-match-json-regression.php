<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Tools\ToolsApiClient;

final class QueueingGenericNoMatchToolsApiClient extends ToolsApiClient
{
    /** @var array<int, array<string, mixed>> */
    private array $queuedResponses;

    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /**
     * @param array<int, array<string, mixed>> $queuedResponses
     */
    public function __construct(array $queuedResponses)
    {
        parent::__construct('https://example.invalid/api', 'test-token');
        $this->queuedResponses = array_values($queuedResponses);
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

        if (!count($this->queuedResponses)) {
            throw new RuntimeException('No queued AI response available for generic no-match regression test.');
        }

        return array_shift($this->queuedResponses);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$client = new QueueingGenericNoMatchToolsApiClient([
    [
        'ok' => true,
        'model' => 'gpt-4o-mini',
        'response' => 'not json at all',
    ],
    [
        'ok' => true,
        'model' => 'gpt-4o-mini',
        'response' => json_encode([
            'can_reply' => true,
            'certainty' => 'medium',
            'reason' => 'Looks answerable but not fully certain.',
            'risk_flags' => ['needs_human_review'],
            'reply' => 'Potential reply that must not be sent.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ],
    [
        'ok' => true,
        'model' => 'gpt-4o-mini',
        'response' => json_encode([
            'can_reply' => true,
            'certainty' => 'high',
            'reason' => 'Routine and safe unmatched support reply.',
            'risk_flags' => [],
            'reply' => 'Thanks for your message. Please send your account number so we can continue.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ],
]);

$mailbox = ['name' => 'Regression mailbox'];
$message = [
    'from' => 'sender@example.test',
    'to' => 'support@example.test',
    'subject' => 'Unmatched question',
    'subject_normalized' => 'Unmatched question',
    'body_text' => 'Question without matching rule.',
    'body_text_reply_aware' => 'Question without matching rule.',
    'spam_assassin' => ['present' => false],
];
$options = [
    'if_condition' => 'If the mail is a routine support question that is safe to answer, we may reply.',
    'reply_instruction' => 'Answer politely and request any missing account details.',
    'ai_model' => 'gpt-4o-mini',
    'ai_reasoning_effort' => 'medium',
];

$invalidJson = $client->evaluateGenericNoMatchReply($mailbox, $message, $options);
assertSameValue(false, $invalidJson['can_reply'] ?? null, 'Invalid JSON must never be replyable.');
assertSameValue('no_matching_rule_generic_ai_invalid_json', $invalidJson['decision_reason_code'] ?? null, 'Invalid JSON must produce the invalid-json reason code.');
assertSameValue('', $invalidJson['reply'] ?? null, 'Invalid JSON must not yield a reply payload.');

$notCertain = $client->evaluateGenericNoMatchReply($mailbox, $message, $options);
assertSameValue(false, $notCertain['can_reply'] ?? null, 'Medium-certainty unmatched mail must still be rejected.');
assertSameValue('no_matching_rule_generic_ai_not_certain', $notCertain['decision_reason_code'] ?? null, 'Non-high certainty must map to the strict not-certain reason code.');
assertSameValue('', $notCertain['reply'] ?? null, 'Non-high certainty must not return a usable reply payload.');

$accepted = $client->evaluateGenericNoMatchReply($mailbox, $message, $options);
assertSameValue(true, $accepted['can_reply'] ?? null, 'High-certainty valid JSON should allow the unmatched-mail reply.');
assertSameValue('high', $accepted['certainty'] ?? null, 'Accepted unmatched-mail reply must stay at high certainty.');
assertSameValue('no_matching_rule_generic_ai_replied', $accepted['decision_reason_code'] ?? null, 'Accepted unmatched-mail reply should use the replied reason code.');
assertSameValue('Thanks for your message. Please send your account number so we can continue.', $accepted['reply'] ?? null, 'Accepted unmatched-mail reply should preserve the AI reply payload.');
assertSameValue('Thanks for your message. Please send your account number so we can continue.', $accepted['response'] ?? null, 'Accepted unmatched-mail reply should expose the same response payload.');

fwrite(STDOUT, "generic-no-match-json-regression: ok\n");


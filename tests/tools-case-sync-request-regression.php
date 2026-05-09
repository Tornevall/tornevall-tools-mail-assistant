<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Tools\ToolsApiClient;

final class CaseSyncRequestToolsApiClient extends ToolsApiClient
{
    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function prepare(string $path, array $payload): array
    {
        return $this->preparePayloadForTransport($path, $payload);
    }

    public function buildError(int $status, array $payload): string
    {
        return $this->buildApiErrorMessage($status, $payload);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$client = new CaseSyncRequestToolsApiClient();
$invalidUtf8 = "Broken \xC3\x28 payload";
$prepared = $client->prepare('/mail-support-assistant/cases/sync', [
    'mailbox_id' => 1,
    'message_id' => 'message@example.test',
    'subject' => str_repeat('S', 1205),
    'headers_raw' => $invalidUtf8,
    'references' => [str_repeat('r', 280), '', 'ok-ref'],
    'selected_rule' => [
        'id' => 12,
        'name' => str_repeat('Rule', 100),
    ],
    'meta' => [
        'raw' => $invalidUtf8,
    ],
]);

assertSameValue(998, strlen((string) ($prepared['subject'] ?? '')), 'Case-sync subject should be truncated to the API validation limit.');
assertSameValue(255, strlen((string) ($prepared['references'][0] ?? '')), 'Each case-sync reference should be truncated to the API validation limit.');
assertSameValue('ok-ref', (string) ($prepared['references'][1] ?? ''), 'Non-empty references should be preserved after cleanup.');
assertTrueValue((bool) preg_match('//u', (string) ($prepared['headers_raw'] ?? '')), 'headers_raw should be normalized into valid UTF-8 before JSON transport.');
assertTrueValue((bool) preg_match('//u', (string) ($prepared['meta']['raw'] ?? '')), 'Nested meta strings should be normalized into valid UTF-8 too.');
assertTrueValue(json_encode($prepared, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== false, 'Prepared case-sync payload should be JSON-encodable.');
assertSameValue(255, strlen((string) (($prepared['selected_rule']['name'] ?? ''))), 'Nested selected_rule.name should be truncated to the same 255-char guardrail.');

$errorMessage = $client->buildError(422, [
    'message' => 'The given data was invalid.',
    'errors' => [
        'headers_raw' => ['The headers raw may not be greater than 200000 characters.'],
        'body_text' => ['The body text field contains invalid UTF-8.'],
    ],
]);

assertTrueValue(strpos($errorMessage, 'HTTP 422') !== false, 'Detailed API error message should include the HTTP status.');
assertTrueValue(strpos($errorMessage, 'headers_raw: The headers raw may not be greater than 200000 characters.') !== false, 'Detailed API error message should include the failing field name and validation detail.');
assertTrueValue(strpos($errorMessage, 'body_text: The body text field contains invalid UTF-8.') !== false, 'Detailed API error message should include multiple field-level validation details.');

echo "tools-case-sync-request-regression: OK\n";


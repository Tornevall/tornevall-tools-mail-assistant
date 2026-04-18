<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class DefaultBccCapturingToolsApiClient extends ToolsApiClient
{
    /** @var array<int, array<string, mixed>> */
    public array $relayPayloads = [];

    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function hasMailToken(): bool
    {
        return true;
    }

    public function sendReplyViaTools(array $payload): array
    {
        $this->relayPayloads[] = $payload;

        return [
            'ok' => true,
            'message' => 'Relay accepted.',
        ];
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function makeTempPath(string $prefix, string $suffix): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);

    return $base . $suffix;
}

$oldTransport = getenv('MAIL_ASSISTANT_MAIL_TRANSPORT');
$oldDefaultBcc = getenv('MAIL_ASSISTANT_DEFAULT_BCC');
$oldFallbackOrder = getenv('MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS');
$oldFallbackToolsApi = getenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API');

putenv('MAIL_ASSISTANT_MAIL_TRANSPORT=tools_api');
putenv('MAIL_ASSISTANT_DEFAULT_BCC=thorne@tornevall.net');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS=');
putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API=false');

try {
    $tools = new DefaultBccCapturingToolsApiClient();
    $runner = new MailAssistantRunner(
        $tools,
        new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json')),
        new MessageStateStore(makeTempPath('mail-assistant-state', '.json'))
    );

    $method = new ReflectionMethod(MailAssistantRunner::class, 'sendReply');
    $method->setAccessible(true);
    $method->invoke(
        $runner,
        [
            'id' => 1,
            'name' => 'Env fallback mailbox',
            'defaults' => [
                'from_name' => 'Support Team',
                'from_email' => 'support@example.test',
                'bcc' => '',
            ],
        ],
        [
            'id' => 2,
            'reply' => [
                'from_name' => 'Support Team',
                'from_email' => 'support@example.test',
                'bcc' => '',
            ],
        ],
        [
            'uid' => 77,
            'message_id' => '<envbcc@example.test>',
            'from' => 'Sender Example <sender@example.test>',
            'to' => 'support@example.test',
            'date' => 'Fri, 18 Apr 2026 18:10:00 +0000',
            'subject' => 'Example',
            'references' => [],
        ],
        'Re: Example',
        'Hello from the standalone assistant.',
        false
    );

    $payload = $tools->relayPayloads[0] ?? null;
    if (!is_array($payload)) {
        throw new RuntimeException('Expected relay payload to be captured when using env default BCC.');
    }

    assertSameValue(['thorne@tornevall.net'], $payload['bcc'] ?? null, 'Env default BCC should be used when rule and mailbox BCC are empty.');
    assertSameValue('sender@example.test', $payload['to'] ?? null, 'Primary reply recipient should still be normalized correctly.');
} finally {
    putenv('MAIL_ASSISTANT_MAIL_TRANSPORT' . ($oldTransport !== false ? '=' . $oldTransport : ''));
    putenv('MAIL_ASSISTANT_DEFAULT_BCC' . ($oldDefaultBcc !== false ? '=' . $oldDefaultBcc : ''));
    putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TRANSPORTS' . ($oldFallbackOrder !== false ? '=' . $oldFallbackOrder : ''));
    putenv('MAIL_ASSISTANT_MAIL_FALLBACK_TOOLS_API' . ($oldFallbackToolsApi !== false ? '=' . $oldFallbackToolsApi : ''));
}

fwrite(STDOUT, "default-bcc-env-regression: ok\n");


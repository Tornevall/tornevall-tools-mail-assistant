<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Support\MessageStateStore;
use MailSupportAssistant\Tools\ToolsApiClient;

final class CapturingRelayToolsApiClient extends ToolsApiClient
{
    /** @var array<int, array<string, mixed>> */
    public array $relayPayloads = [];

    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
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

$tools = new CapturingRelayToolsApiClient();
$runner = new MailAssistantRunner(
    $tools,
    new Logger(makeTempPath('mail-assistant-log', '.log'), makeTempPath('mail-assistant-last-run', '.json')),
    new MessageStateStore(makeTempPath('mail-assistant-state', '.json'))
);

$resolveRecipients = new ReflectionMethod(MailAssistantRunner::class, 'resolveReplyRecipients');
$resolveRecipients->setAccessible(true);
$resolvedRecipients = $resolveRecipients->invoke(
    $runner,
    'Sender Name <sender@example.test>',
    [
        'From: Support Team <support@example.test>',
        'Cc: Team One <team1@example.test>; team2@example.test',
        "Bcc: Audit One <audit1@example.test>;\r\n audit2@example.test",
    ]
);

if (!is_array($resolvedRecipients)) {
    throw new RuntimeException('Expected normalized recipients to be returned.');
}

$method = new ReflectionMethod(MailAssistantRunner::class, 'sendReplyViaToolsRelay');
$method->setAccessible(true);
$method->invoke(
    $runner,
    ['id' => 9, 'name' => 'Regression mailbox'],
    ['id' => 15],
    [
        'message_id' => '<thread@example.test>',
        'uid' => 901,
        'from' => 'sender@example.test',
        'to' => 'support@example.test',
        'date' => 'Fri, 18 Apr 2026 17:00:00 +0000',
    ],
    'Sender Name <sender@example.test>',
    'Re: Example',
    ['text' => 'Reply text', 'html' => '<p>Reply text</p>'],
    [
        'From: Support Team <support@example.test>',
        'Cc: Team One <team1@example.test>; team2@example.test',
        "Bcc: Audit One <audit1@example.test>;\r\n audit2@example.test",
    ],
    $resolvedRecipients,
    'tools_api_primary'
);

$payload = $tools->relayPayloads[0] ?? null;
if (!is_array($payload)) {
    throw new RuntimeException('Expected the relay payload to be captured.');
}

assertSameValue('sender@example.test', $payload['to'] ?? null, 'Relay payload should use the normalized primary recipient.');
assertSameValue(['team1@example.test', 'team2@example.test'], $payload['cc'] ?? null, 'Relay payload should split and normalize CC recipients.');
assertSameValue(['audit1@example.test', 'audit2@example.test'], $payload['bcc'] ?? null, 'Relay payload should split and normalize BCC recipients.');
assertSameValue('tools_api_primary', $payload['mode'] ?? null, 'Relay payload should keep the transport mode for diagnostics.');

fwrite(STDOUT, "bcc-routing-regression: ok\n");


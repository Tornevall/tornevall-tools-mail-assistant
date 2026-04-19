<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;

final class NonCompliantReplyToolsApiClient extends ToolsApiClient
{
    public function __construct()
    {
        parent::__construct('https://example.invalid/api', 'test-token');
    }

    public function generateAiReply(array $mailbox, array $rule, array $message): array
    {
        return [
            'ok' => true,
            'model' => 'gpt-5.4',
            'response' => "Hej Ben,\n\nTack för din notifiering. Vi tar uppgifterna på största allvar och kommer att undersöka situationen. Vi ser till att ta bort det aktuella materialet så snabbt som möjligt.\n\nMed vänlig hälsning,\nTornevall Networks Support",
        ];
    }
}

function makeAccessible(ReflectionMethod $method): void
{
    $method->setAccessible(true);
}

$runner = new MailAssistantRunner(
    new NonCompliantReplyToolsApiClient(),
    new Logger(sys_get_temp_dir() . '/mail-assistant-instruction-compliance.log', sys_get_temp_dir() . '/mail-assistant-instruction-compliance-last-run.json')
);

$method = new ReflectionMethod($runner, 'buildReplyText');
makeAccessible($method);

$mailbox = ['defaults' => ['footer' => 'Kind regards,\nTornevall Networks Support']];
$message = [
    'from' => 'notice@example.test',
    'to' => 'support@example.test',
    'subject' => 'Copyright notice',
    'body_text' => 'Notice body',
    'body_text_reply_aware' => 'Notice body',
];
$rule = [
    'reply' => [
        'ai_enabled' => true,
        'template_text' => '',
        'custom_instruction' => implode("\n", [
            'You are generating an automated email reply to a misdirected copyright notice.',
            '',
            'Write only the email body in English.',
            '',
            'The reply must state that:',
            '- the message was received by Tornevall Networks in error,',
            '- Tornevall Networks does not handle these notices,',
            '- the current message is being deleted instead of handled,',
            '- the sender must rerun the process and submit the notice directly to No ACK Group Holding AB at abuse@no-ack.net,',
            '- future notices of the same kind must also be sent there directly.',
        ]),
    ],
];

try {
    $method->invoke($runner, $mailbox, $rule, $message);
    throw new RuntimeException('Expected non-compliant AI reply to be rejected.');
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (strpos($error, 'English-only requirement') === false
        && strpos($error, 'required contact address') === false
        && strpos($error, 'required statement') === false
        && strpos($error, 'redirect-only') === false) {
        throw new RuntimeException('Unexpected compliance failure message: ' . $error);
    }
}

fwrite(STDOUT, "ai-instruction-compliance-regression: ok\n");


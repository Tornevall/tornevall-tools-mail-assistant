<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\MimeDecoder;
use MailSupportAssistant\Runner\MailAssistantRunner;
use MailSupportAssistant\Support\Logger;
use MailSupportAssistant\Tools\ToolsApiClient;

function makeMethodAccessible(ReflectionMethod $method): void
{
    $method->setAccessible(true);
}

function assertContainsFragment(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Missing fragment: ' . $needle);
    }
}

$rawBody = <<<TEXT
From: niclas ahlqvist <niclas.ahlqvist@lime.tech>
Subject: delist not working
Sender IP: 212.247.43.226

Message Body:
Hi,

Delisting tool not working due to cloudflare issues.

--
This e-mail was sent from a contact form on TornevallNET Base (https://www.tornevall.net).
TEXT;

$replyAwareBody = MimeDecoder::stripQuotedReplyText($rawBody);
$summary = MimeDecoder::extractRequestSummaryText($replyAwareBody, 900);

assertContainsFragment('Sender IP: 212.247.43.226', $summary, 'Cleartext contact-form summaries should preserve the sender IP.');
assertContainsFragment('Delisting tool not working due to cloudflare issues.', $summary, 'Cleartext contact-form summaries should preserve the actual problem description.');

$runner = new MailAssistantRunner(
    new class extends ToolsApiClient {
        public function __construct()
        {
            parent::__construct('https://example.invalid/api', 'test-token');
        }
    },
    new Logger(sys_get_temp_dir() . '/mail-assistant-contact-form.log', sys_get_temp_dir() . '/mail-assistant-contact-form-last-run.json')
);

$method = new ReflectionMethod($runner, 'buildReplyContent');
makeMethodAccessible($method);

$result = $method->invoke(
    $runner,
    'Thanks for your message.',
    [
        'body_text_raw' => $rawBody,
        'body_text' => $rawBody,
        'body_text_reply_aware' => $replyAwareBody,
    ],
    []
);

if (!is_array($result)) {
    throw new RuntimeException('Expected reply content payload.');
}

assertContainsFragment('Summary of your request', (string) ($result['text'] ?? ''), 'Reply content should still append the original request summary.');
assertContainsFragment('Delisting tool not working due to cloudflare issues.', (string) ($result['text'] ?? ''), 'The appended request summary should include the real issue description from clear-text contact-form mail.');
assertContainsFragment('Sender IP: 212.247.43.226', (string) ($result['text'] ?? ''), 'The appended request summary should include the sender IP from clear-text contact-form mail.');

fwrite(STDOUT, "contact-form-summary-regression: ok\n");

